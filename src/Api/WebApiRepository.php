<?php

namespace Pim\Bundle\TextmasterBundle\Api;

use Textmaster\Client;
use Textmaster\Exception\RuntimeException;
use Textmaster\Model\Document;
use Textmaster\Model\Project;
use Textmaster\Model\ProjectInterface;

/**
 * Calls to TextMaster php API
 *
 * @author    Jean-Marie Leroux <jean-marie.leroux@akeneo.com>
 * @copyright 2016 Akeneo SAS (https://textmaster.com)
 * @license   http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
class WebApiRepository implements WebApiRepositoryInterface
{
    /** @var Client */
    protected $clientApi;

    /**
     * @param Client $clientApi
     */
    public function __construct(Client $clientApi)
    {
        $this->clientApi = $clientApi;
    }

    /**
     * @param array  $documents
     * @param string $projectId
     *
     * @return array
     */
    public function sendProjectDocuments(array $documents, $projectId)
    {
        $projectApi = $this->clientApi->project();

        return $projectApi->documents($projectId)->batchCreate($documents);
    }

    /**
     * @param array $data
     *
     * @return array
     */
    public function createProject(array $data)
    {
        return $this->clientApi->projects()->create($data);
    }

    /**
     * @param array  $data
     * @param string $projectId
     *
     * @return array
     */
    public function updateProject(array $data, $projectId)
    {
        return $this->clientApi->projects()->update($projectId, $data);
    }

    /**
     * @param array $filters
     *
     * @return ProjectInterface[]
     */
    public function getProjects(array $filters)
    {
        $projectApi = $this->clientApi->project();
        $response = $projectApi->filter($filters);

        $projects = [];
        foreach ($response['projects'] as $projectData) {
            $projects[] = new Project($this->clientApi, $projectData);
        }

        return $projects;
    }

    /**
     * @param array $filters
     *
     * @return string[]
     */
    public function getProjectCodes(array $filters)
    {
        $projects = $this->getProjects($filters);
        $projectsCodes = [];
        foreach ($projects as $project) {
            $projectsCodes[] = $project->getId();
        }

        return $projectsCodes;
    }

    /**
     * @param string $projectCode
     *
     * @return ProjectInterface
     */
    public function getProject($projectCode)
    {
        $projectApi = $this->clientApi->project();
        $response = $projectApi->show($projectCode);

        return new Project($this->clientApi, $response);
    }

    /**
     * @param string $projectCode
     *
     * @return ProjectInterface
     */
    public function launchProject($projectCode)
    {
        $projectApi = $this->clientApi->project();
        $response = $projectApi->launch($projectCode);

        return new Project($this->clientApi, $response);
    }

    /**
     * @param string $projectId
     *
     * @return array
     */
    public function cancelProject($projectId)
    {
        $projectApi = $this->clientApi->project();

        return $projectApi->cancel($projectId);
    }

    /**
     * @param string $projectId
     *
     * @return array
     */
    public function archiveProject($projectId)
    {
        $projectApi = $this->clientApi->project();

        return $projectApi->archive($projectId);
    }

    /**
     * @param array  $filters
     * @param string $projectCode
     *
     * @return \Textmaster\Model\DocumentInterface[]
     */
    public function getDocuments(array $filters, $projectCode)
    {
        $documentsApi = $this->clientApi->project()->documents($projectCode);
        $documents = $documentsApi->filter($filters);
        $models = [];
        foreach ($documents['documents'] as $documentData) {
            $models[] = new Document($this->clientApi, $documentData);
        }

        return $models;
    }

    /**
     * @param string[] $pimLocaleCodes
     *
     * @return \string[]
     */
    public function getAvailableLocaleCodes(array $pimLocaleCodes)
    {
        $pimLocaleCodes = array_map(function ($localeCode) {
            return strtolower(str_replace('_', '-', $localeCode));
        }, $pimLocaleCodes);

        $availableLocales = [];
        $page = 1;
        do {
            try {
                $tmLocales = $this->clientApi->locales()->abilities('translation', $page);
                foreach ($tmLocales['data'] as $data) {
                    if (in_array($data['language_from'], $pimLocaleCodes) && in_array($data['language_to'], $pimLocaleCodes)) {
                        $availableLocales['from'][$data['language_from']] = 1;
                        $availableLocales['to'][$data['language_to']] = 1;
                    }
                }
                $page = $page + 1;
            } catch (RuntimeException $e) {
                $tmLocales = null;
            }
        } while (count($tmLocales['data']) > 0
            && count($availableLocales['from']) < count($pimLocaleCodes)
            && count($availableLocales['to']) < count($pimLocaleCodes)
        );

        return $pimLocaleCodes;
    }

    /**
     * @return array
     */
    public function getCategories()
    {
        $response = $this->clientApi->categories()->all();

        $categories = [];
        foreach ($response['categories'] as $category) {
            $categories[$category['code']] = $category['value'];
        }

        asort($categories);

        return $categories;
    }
}
