<?php
namespace AppBundle\Command;

use AppBundle\ProviderBundle\Document\Provider;
use AppBundle\RecordBundle\Document\Record;
use AppBundle\ReportBundle\Document\CompletenessReport;
use AppBundle\ReportBundle\Document\CompletenessTrend;
use AppBundle\ReportBundle\Document\FieldReport;
use AppBundle\ReportBundle\Document\FieldTrend;
use Phpoaipmh\Endpoint;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class FetchDataCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            // the name of the command (the part after "bin/console")
            ->setName('app:fetch-data')
            ->addArgument("url", InputArgument::OPTIONAL, "The URL of the Datahub")

            // the short description shown while running "php bin/console list"
            ->setDescription('Fetches all data from the Datahub and stores the relevant information in a local database.')

            // the full command description shown when running the command with
            // the "--help" option
            ->setHelp('This command fetches all data from the Datahub and stores the relevant information in a local database.\nOptional parameter: the URL of the datahub. If the URL equals "skip", it will not fetch data and use whatever is currently in the database.')
        ;
    }

    private function getDocumentManager()
    {
        return $this->getContainer()->get('doctrine_mongodb')->getManager();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $url = $input->getArgument("url");
        $skip = false;
        if(!$url) {
            $url = $this->getContainer()->getParameter('datahub_url');
        } elseif($url === 'skip') {
            $skip = true;
        }

        $namespace = $this->getContainer()->getParameter('datahub.namespace');
        $metadataPrefix = $this->getContainer()->getParameter('datahub.metadataprefix');
        $dataDef = $this->getContainer()->getParameter('data_definition');
        $providerDef = $this->getContainer()->getParameter('providers');

        $providers = null;
        if(!$skip) {
            $myEndpoint = Endpoint::build($url);
            $recs = $myEndpoint->listRecords($metadataPrefix);

            $dm = $this->getDocumentManager();
            $dm->getDocumentCollection('RecordBundle:Record')->remove([]);

            $providers = array();
            $i = 0;
            foreach ($recs as $rec) {
                $i++;
                $data = $rec->metadata->children($namespace, true);
                $fetchedData = $this->fetchData($dataDef, $namespace, $data, $providers, $providerDef);
                if(array_key_exists('provider', $fetchedData) && count($fetchedData['provider']) > 0) {
                    $record = new Record();
                    $record->setProvider($fetchedData['provider'][0]);
                    $record->setData($fetchedData);
                    $dm->persist($record);
                }
                if($i % 1000 === 0) {
                    echo 'At ' . $i . PHP_EOL;
                }
            }
            $dm->flush();
            $this->storeProviders($providers);
        }
        else {
            $providers = $this->getDocumentManager()->getRepository('ProviderBundle:Provider')->findAll();
        }

        $this->generateAndStoreReport($dataDef, $providers);
    }

    private function fetchData($dataDef, $namespace, $data, &$providers, $providerDef) {
        $result = array();
        foreach ($dataDef as $key => $value) {
            if($key === 'parent_xpath' || $key === 'csv') {
                continue;
            }
            if(array_key_exists('xpath', $value)) {
                $xpath = $this->buildXpath($value['xpath'], $namespace);
                $res = $data->xpath($xpath);
                if ($res) {
                    $arr = array();
                    foreach ($res as $resChild) {
                        $child = (string)$resChild;
                        if($key === 'id') {
                            $idArr = array('id' => $child);
                            $attributes = $resChild->attributes($namespace, true);
                            if ($attributes) {
                                foreach ($attributes as $attributeKey => $attributeValue) {
                                    $idArr[(string)$attributeKey] = (string)$attributeValue;
                                }
                            }
                            $arr[] = $idArr;
                        }
                        else {
                            if (strlen($child) > 0 && strtolower($child) !== 'n/a') {
                                if ($key === 'provider') {
                                    $arr[] = $this->addToProviders($child, $providers, $providerDef);
                                } else {
                                    $arr[] = $child;
                                }
                            }
                        }
                    }
                    $result[$key] = $arr;
                } else {
                    $result[$key] = null;
                }
            }
            elseif(array_key_exists('parent_xpath', $value)) {
                $xpath = $this->buildXpath($value['parent_xpath'], $namespace);
                $res = $data->xpath($xpath);
                if ($res) {
                    foreach($res as $r) {
                        $result[$key][] = $this->fetchData($value, $namespace, $r, $providers, $providerDef);
                    }
                } else {
                    $result[$key] = null;
                }
            }
        }
        return $result;
    }

    private function buildXpath($xpath, $namespace)
    {
        $xpath = str_replace('[@', '[@' . $namespace . ':', $xpath);
        $xpath = preg_replace('/\[([^@])/', '[' . $namespace . ':${1}', $xpath);
        $xpath = preg_replace('/\/([^\/])/', '/' . $namespace . ':${1}', $xpath);
        if(strpos($xpath, '/') !== 0) {
            $xpath = $namespace . ':' . $xpath;
        }
        $xpath = 'descendant::' . $xpath;
        return $xpath;
    }

    private function addToProviders($providerName, &$providers, $providerDef)
    {
        foreach ($providers as $provider) {
            if ($provider->getName() === $providerName) {
                return $provider->getIdentifier();
            }
        }
        if(array_key_exists($providerName, $providerDef))
            $providerId = $providerDef[$providerName];
        else {
            $providerId = preg_replace("/[^A-Za-z0-9 ]/", '', $providerName);
            while(strpos($providerId, '  ') > -1) {
                $providerId = str_replace('  ', ' ', $providerId);
            }
            $providerId = str_replace(' ', '_', $providerId);
            $providerId = strtolower($providerId);
            if(strlen($providerId) > 25) {
                $providerId = substr($providerId, 0, 25);
            }
        }

        $provider = new Provider();
        $provider->setIdentifier($providerId);
        $provider->setName($providerName);
        $providers[] = $provider;
        echo 'Provider added: ' . $providerName . PHP_EOL;

        return $providerId;
    }

    private function storeProviders($providers)
    {
        $dm = $this->getDocumentManager();
        $dm->getDocumentCollection('ProviderBundle:Provider')->remove([]);
        foreach($providers as $provider) {
            $dm->persist($provider);
        }
        $dm->flush();
    }

    private function generateAndStoreReport($dataDef, $providers)
    {
        $dm = $this->getDocumentManager();
        $dm->getDocumentCollection('ReportBundle:CompletenessReport')->remove([]);
        $dm->getDocumentCollection('ReportBundle:CompletenessTrend')->remove([]);
        $dm->getDocumentCollection('ReportBundle:FieldReport')->remove([]);
        $dm->getDocumentCollection('ReportBundle:FieldTrend')->remove([]);

        foreach($providers as $provider) {
            $providerId = $provider->getIdentifier();

            $allRecords = $this->getDocumentManager()->getRepository('RecordBundle:Record')->findBy(array('provider' => $providerId));

            if(!$allRecords) {
                continue;
            }

            $completenessReport = new CompletenessReport();
            $completenessReport->setProvider($providerId);

            $fields = array('minimum' => array(), 'basic' => array(), 'extended' => array());

            foreach ($dataDef as $key => $value) {
                if (array_key_exists('xpath', $value)) {
                    $fields[$value['class']][$key] = array();
                }
                elseif (array_key_exists('parent_xpath', $value)) {
                    foreach ($value as $k => $v) {
                        if ($k === 'parent_xpath' || $k == 'csv') {
                            continue;
                        }
                        if (array_key_exists('xpath', $v)) {
                            $fields[$v['class']][$key . '/' . $k] = array();
                        }
                    }
                }
            }

            $termIds = array();
            $termsWithIdFields = $this->getContainer()->getParameter('terms_with_ids');
            foreach($termsWithIdFields as $field) {
                $termIds[$field] = array();
            }

            foreach ($allRecords as $record) {
                $data = $record->getData();
                $minimumComplete = true;
                $basicComplete = true;
                foreach ($dataDef as $key => $value) {
                    if (array_key_exists('xpath', $value)) {
                        if (is_array($data) && array_key_exists($key, $data) && is_array($data[$key])) {
                            if(count($data[$key]) > 0) {
                                $fields[$value['class']][$key][] = $record->getId();
                            }
                        }
                        else {
                            if ($value['class'] == 'minimum') {
                                $minimumComplete = false;
                                $basicComplete = false;
                            } elseif ($value['class'] == 'basic') {
                                $basicComplete = false;
                            }
                        }
                    } elseif (array_key_exists('parent_xpath', $value)) {
                        foreach ($value as $k => $v) {
                            if ($k === 'parent_xpath' || $k === 'csv') {
                                continue;
                            }
                            if (array_key_exists('xpath', $v)) {
                                $found = false;
                                foreach ($data as $fieldName => $fieldValues) {
                                    if($fieldName === $key && $fieldValues) {
                                        foreach ($fieldValues as $fieldValue) {
                                            if ($fieldValue) {
                                                if (array_key_exists($k, $fieldValue) && count($fieldValue[$k]) > 0) {
                                                    $found = true;
                                                    if($k == 'term') {
                                                        if(array_key_exists('id', $fieldValue) && count($fieldValue['id']) > 0) {
                                                            if(!array_key_exists($fieldValue['term'][0], $termIds[$key])) {
                                                                $localId = null;
                                                                foreach($fieldValue['id'] as $termId) {
                                                                    if($termId['type'] === 'local') {
                                                                        $localId = $termId['id'];
                                                                        break;
                                                                    }
                                                                }
                                                                if($localId) {
                                                                    $termIds[$key][$fieldValue['term'][0]] = $localId;
                                                                }
                                                            }
                                                        }
                                                    }
                                                }
                                            }
                                        }
                                    }
                                }
                                if($found) {
                                    $fields[$v['class']][$key . '/' . $k][] = $record->getId();
                                }
                                else {
                                    if ($v['class'] == 'minimum') {
                                        $minimumComplete = false;
                                        $basicComplete = false;
                                    } elseif ($v['class'] == 'basic') {
                                        $basicComplete = false;
                                    }
                                }
                            }
                        }
                    }
                }
                $completenessReport->incrementTotal();
                if ($minimumComplete) {
                    $completenessReport->incrementMinimum();
                }
                if ($basicComplete) {
                    $completenessReport->incrementBasic();
                }
            }

            $dm->persist($completenessReport);

            $completenessTrend = new CompletenessTrend();
            $completenessTrend->setProvider($providerId);
            $completenessTrend->setTimestamp(new \MongoDate());
            $completenessTrend->setTotal($completenessReport->getTotal());
            $completenessTrend->setMinimum($completenessReport->getMinimum());
            $completenessTrend->setBasic($completenessReport->getBasic());
            $dm->persist($completenessTrend);

            $fieldReport = new FieldReport();
            $fieldReport->setTotal($completenessReport->getTotal());
            $fieldReport->setProvider($providerId);
            $fieldReport->setMinimum($fields['minimum']);
            $fieldReport->setBasic($fields['basic']);
            $fieldReport->setExtended($fields['extended']);
            $dm->persist($fieldReport);

            $termsWithIds = array();
            foreach($termIds as $key => $terms) {
                $termsWithIds[$key] = count($terms);
            }
            $fieldTrend = new FieldTrend();
            $fieldTrend->setProvider($providerId);
            $fieldTrend->setTimestamp(new \MongoDate());
            $fieldTrend->setCounts($termsWithIds);
            $dm->persist($fieldTrend);

            $dm->flush();
        }
    }
}
