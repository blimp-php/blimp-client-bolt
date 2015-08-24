<?php

namespace Bolt\Extension\Blimp\Client\Storage;

use Bolt;
use Bolt\Events\StorageEvent;
use Bolt\Events\StorageEvents;
use Bolt\Exception\StorageException;
use Bolt\Helpers\Arr;
use Bolt\Helpers\Str;
use Bolt\Legacy\Content;
use Bolt\Pager;
use utilphp\util;
use Bolt\Translation\Translator as Trans;

class BlimpStorage extends \Bolt\Legacy\Storage {
    protected $app;

    public function __construct(Bolt\Application $app) {
        parent::__construct($app);

        $this->app = $app;
    }

    public function publish(Content $content, $comment = null) {
        $contenttype = $content->contenttype;
        $fieldvalues = $content->values;

        // Test to see if this is a new record, or an update
        if (empty($fieldvalues['blimp_id'])) {
            $create = true;
        } else {
            $create = false;
        }

        // Update the content object
        $content->setValues($fieldvalues);

        // Decide whether to insert a new record, or update an existing one.
        if ($create) {
            $this->insertContent($content, $comment);
        } else {
            $this->updateContent($content, $comment);
        }

        $content->setValue('status', 'published');

        $comment = Trans::__('The content has been published.');

        $result = $this->app['storage']->saveContent($content, $comment);

        return $result;
    }

    /**
     * getContent based on a 'human readable query'.
     *
     * Used directly by {% setcontent %} but also in other parts.
     *
     * This code has been split into multiple methods in the spirit of
     * separation of concerns, but the situation is still far from ideal.
     *
     * Where applicable each 'concern' notes the coupling in the local
     * documentation.
     *
     * @param string $textquery
     * @param string $parameters
     * @param array  $pager
     * @param array  $whereparameters
     *
     * @return array
     */
    public function getContent($textquery, $parameters = '', &$pager = [], $whereparameters = []) {
        // Start the 'stopwatch' for the profiler.
        $this->app['stopwatch']->start('bolt.getcontent', 'blimp-http');

        // $whereparameters is passed if called from a compiled template. If present, merge it with $parameters.
        if (!empty($whereparameters)) {
            $parameters = array_merge((array) $parameters, (array) $whereparameters);
        }

        $logNotFound = false;
        if (isset($parameters['log_not_found'])) {
            $logNotFound = $parameters['log_not_found'];
            unset($parameters['log_not_found']);
        }

        // Decode this textquery
        $decoded = $this->decodeContentQuery($textquery, $parameters);
        if ($decoded === false) {
            $this->app['logger.system']->error("Not a valid query: '$textquery'", ['event' => 'storage']);
            $this->app['stopwatch']->stop('bolt.getcontent');

            return false;
        }

        // Run the actual queries
        list($results, $totalResults) = call_user_func(
            $decoded['queries_callback'],
            $decoded,
            $parameters
        );

        // Perform post hydration ordering
        if ($decoded['order_callback'] !== false) {
            if (is_scalar($decoded['order_callback']) && ($decoded['order_callback'] == 'RANDOM')) {
                shuffle($results);
            } else {
                uasort($results, $decoded['order_callback']);
            }
        }

        // Perform pagination if necessary, but never paginate when 'returnsingle' is used.
        $offset = 0;
        $limit = false;
        if (($decoded['self_paginated'] === false) && (isset($decoded['parameters']['page'])) && (!$decoded['return_single'])) {
            $offset = ($decoded['parameters']['page'] - 1) * $decoded['parameters']['limit'];
            $limit = $decoded['parameters']['limit'];
        }
        if ($limit !== false) {
            $results = array_slice($results, $offset, $limit);
        }

        // Return content
        if ($decoded['return_single']) {
            if (!empty($results) && util::array_first_key($results)) {
                $this->app['stopwatch']->stop('bolt.getcontent');

                return util::array_first($results);
            }

            if ($logNotFound) {
                $msg = sprintf(
                    "Requested specific query '%s', not found.",
                    $textquery
                );
                $this->app['logger.system']->error($msg, ['event' => 'storage']);
            }
            $this->app['stopwatch']->stop('bolt.getcontent');

            return false;
        }

        // Set up the $pager array with relevant values, but only if we requested paging.
        if (isset($decoded['parameters']['paging'])) {
            $pagerName = implode('_', $decoded['contenttypes']);
            $pager = [
                'for' => $pagerName,
                'count' => $totalResults,
                'totalpages' => ceil($totalResults / $decoded['parameters']['limit']),
                'current' => $decoded['parameters']['page'],
                'showing_from' => ($decoded['parameters']['page'] - 1) * $decoded['parameters']['limit'] + 1,
                'showing_to' => ($decoded['parameters']['page'] - 1) * $decoded['parameters']['limit'] + count($results),
            ];
            $this->setPager($pagerName, $pager);
            $this->app['twig']->addGlobal('pager', $this->getPager());
        }

        $this->app['stopwatch']->stop('bolt.getcontent');

        return $results;
    }

    /**
     * Execute the content queries.
     *
     * This is tightly coupled to $this->getContent()
     *
     * @see $this->getContent()
     *
     * @param array $decoded
     *
     * @return array
     */
    protected function executeGetRequest($decoded) {
        // Perform actual queries and hydrate
        $totalResults = false;
        $results = false;
        foreach ($decoded['queries'] as $query) {
            $uri = $query['collection'];
            $params = $query['query'];

            if (!empty($query['resource'])) {
                $uri .= '/' . $query['resource'];
            } else {
                if ($decoded['self_paginated'] === true) {
                    if (isset($decoded['parameters']['paging']) && $decoded['parameters']['paging'] === true) {
                        $params['offset'] = ($decoded['parameters']['page'] - 1) * $decoded['parameters']['limit'];
                    } else {
                        $offset = null;
                    }
                }

                if (!empty($decoded['parameters']['limit'])) {
                    // If we're not paging, but we _did_ provide a limit.
                    $params['limit'] = $decoded['parameters']['limit'];
                }

                if (!empty($query['order'])) {
                    $params['orderBy'] = $query['order'];
                }
            }

            $res = $this->app['blimp_client.request']('GET', $uri, $params);

            if ($res['status'] == 200) {
                if (!empty($query['resource'])) {
                    $rows = [$res['data']];
                } else {
                    $rows = $res['data']['elements'];
                    $totalResults = $res['data']['count'];
                }
            } else {
                return false;
            }

            // Convert the row 'arrays' into \Bolt\Legacy\Content objects.
            // Only get the Taxonomies and Relations if we have to.
            $rows = $this->hydrateRows($query['contenttype'], $rows, $decoded['hydrate']);

            if ($results === false) {
                $results = $rows;
            } else {
                // We can no longer maintain keys when merging subresults
                $results = array_merge($results, array_values($rows));
            }
        }

        if ($totalResults === false) {
            $totalResults = count($results);
        }

        return [$results, $totalResults];
    }

    /**
     * Save a record.
     *
     * @param Content $content
     * @param string  $comment
     *
     * @throws \Bolt\Exception\StorageException
     *
     * @return int
     */
    public function saveContent(Content $content, $comment = null) {
        $contenttype = $content->contenttype;
        $fieldvalues = $content->values;

        if (empty($contenttype)) {
            $this->app['logger.system']->error('Contenttype is required for ' . __FUNCTION__, ['event' => 'exception']);
            throw new StorageException('Contenttype is required for ' . __FUNCTION__);
        }

        // Test to see if this is a new record, or an update
        if (empty($fieldvalues['id'])) {
            $create = true;
        } else {
            $create = false;
        }

        // We need to verify if the slug is unique. If not, we update it.
        $getId = $create ? null : $fieldvalues['id'];
        $fieldvalues['slug'] = $this->getUri($fieldvalues['slug'], $getId, $contenttype['slug'], false, false);

        // Update the content object
        $content->setValues($fieldvalues);

        // Dispatch pre-save event
        if (!$this->inDispatcher && $this->app['dispatcher']->hasListeners(StorageEvents::PRE_SAVE)) {
            $event = new StorageEvent($content, ['contenttype' => $contenttype, 'create' => $create]);
            $this->app['dispatcher']->dispatch(StorageEvents::PRE_SAVE, $event);
        }

        // Decide whether to insert a new record, or update an existing one.
        if ($create) {
            $this->insertContent($content, $comment);
        } else {
            $this->updateContent($content, $comment);
        }

        // Update taxonomy and record relationships
        // $this->updateTaxonomy($contenttype, $content->values['id'], $content->taxonomy);
        // $this->updateRelation($contenttype, $content->values['id'], $content->relation);

        // Dispatch post-save event
        if (!$this->inDispatcher && $this->app['dispatcher']->hasListeners(StorageEvents::POST_SAVE)) {
            // Block loops
            $this->inDispatcher = true;

            $event = new StorageEvent($content, ['contenttype' => $contenttype, 'create' => $create]);
            $this->app['dispatcher']->dispatch(StorageEvents::POST_SAVE, $event);

            // Re-enable the dispatcher
            $this->inDispatcher = false;
        }

        return $content->values['id'];
    }

    /**
     * Insert a new contenttype record in the database.
     *
     * @param \Bolt\Legacy\Content $content Record content to insert
     * @param string               $comment Editor's comment
     *
     * @return boolean
     */
    protected function insertContent(Content $content, $comment = null) {
        $collection = $this->getContentTypeCollection($content->contenttype);

        // Get the JSON database prepared values and make sure it's valid
        $fieldvalues = $this->getValidSaveData($content->getValues(true), $content->contenttype);

        $res = $this->app['blimp_client.request']('POST', $collection, null, $fieldvalues);

        $synced = $content->contenttype['blimp_mode'] === 'sync';

        // Do the actual insert, and log it.
        if ($res['status'] == 201) {
            $id = $res['data']['id'];

            if($synced) {
                $content->setValue('blimp_id', $id);
            } else {
                $content->setValue('id', $id);
                $this->logInsert($content->contenttype['slug'], $id, $fieldvalues, $comment);
            }

            return true;
        }
    }

    /**
     * Update a Bolt contenttype record.
     *
     * @param \Bolt\Legacy\Content $content The content object to be updated
     * @param string               $comment Add a comment to save with change.
     *
     * @throws \Bolt\Exception\StorageException
     *
     * @return bool
     */
    private function updateContent(Content $content, $comment = null) {
        $synced = $content->contenttype['blimp_mode'] === 'sync';

        $collection = $this->getContentTypeCollection($content->contenttype);

        $id = $synced ? $content['blimp_id'] : $content['id'];

        // Test that the record exists in the database
        $oldContent = $this->findContent($collection, $id);
        if (empty($oldContent)) {
            if($synced) {
                return $this->insertContent($content, $comment);
            }

            throw new StorageException('Attempted to update a non-existent record');
        }

        // Get the JSON database prepared values and make sure it's valid
        $fieldvalues = $this->getValidSaveData($content->getValues(true), $content->contenttype);

        $uri = $collection . '/' . $id;
        $res = $this->app['blimp_client.request']('PUT', $uri, null, $fieldvalues);

        if ($res['status'] == 200) {
            $this->logUpdate($content->contenttype['slug'], $content['id'], $fieldvalues, $oldContent, $comment);

            return true;
        }
    }

    /**
     * Delete a record.
     *
     * @param string  $contenttype
     * @param integer $id
     *
     * @throws \Bolt\Exception\StorageException
     *
     * @return integer The number of affected rows.
     */
    public function deleteContent($contenttype, $id) {
        if (empty($contenttype)) {
            $this->app['logger.system']->error('Contenttype is required for' . __FUNCTION__, ['event' => 'exception']);
            throw new StorageException('Contenttype is required for ' . __FUNCTION__);
        }

        $synced = false;
        if (is_string($contenttype)) {
            $ct = $this->getContentType($contenttype);
            $synced = $ct['blimp_mode'] === 'sync';
        } else {
            $synced = $contenttype['blimp_mode'] === 'sync';
            $contenttype = $contenttype['slug'];
        }

        $collection = $this->getContentTypeCollection($contenttype);

        // Test that the record exists in the database
        if($synced) {
            $oldContent = parent::findContent(parent::getContenttypeTablename($contenttype), $id);
        } else {
            $oldContent = $this->findContent($collection, $id);
        }

        if (empty($oldContent)) {
            throw new StorageException('Attempted to delete a non-existent record');
        }

        $real_id = null;
        if($synced) {
            if(!empty($oldContent['blimp_id'])) {
                $real_id = $oldContent['blimp_id'];
            }
        } else {
            $real_id = $id;
        }

        // Dispatch pre-delete event
        if (!$synced && $this->app['dispatcher']->hasListeners(StorageEvents::PRE_DELETE)) {
            $event = new StorageEvent($oldContent, ['contenttype' => $contenttype]);
            $this->app['dispatcher']->dispatch(StorageEvents::PRE_DELETE, $event);
        }

        $this->logDelete($contenttype, $id, $oldContent);

        if(!empty($real_id)) {
            $uri = $collection . '/' . $real_id;
            $res = $this->app['blimp_client.request']('DELETE', $uri);

            if ($res['status'] == 200 || $synced && $res['status'] == 404) {
                if($synced) {
                    return $this->app['storage']->deleteContent($contenttype, $id);
                }

                if ($this->app['dispatcher']->hasListeners(StorageEvents::POST_DELETE)) {
                    $event = new StorageEvent($oldContent, ['contenttype' => $contenttype]);
                    $this->app['dispatcher']->dispatch(StorageEvents::POST_DELETE, $event);
                }

                return 1;
            }
        } else {
            return $this->app['storage']->deleteContent($contenttype, $id);
        }

        return 0;
    }

    /**
     * Find record from Content Type and Content Id.
     *
     * @param string $tablename Table name
     * @param int    $contentId Content Id
     *
     * @return array
     */
    protected function findContent($collection, $resource) {
        $uri = $collection . '/' . $resource;
        $res = $this->app['blimp_client.request']('GET', $uri, $params);

        if ($res['status'] == 200) {
            return $res['data'];
        }

        return false;
    }

    /**
     * Get the tablename with prefix from a given Contenttype.
     *
     * @param string|array $contenttype
     *
     * @return string
     */
    public function getContentTypeCollection($contenttype) {
        if (is_string($contenttype)) {
            $contenttype = $this->getContentType($contenttype);
        }

        if (!empty($contenttype['blimp_collection'])) {
            return $contenttype['blimp_collection'];
        }

        return '/' . $contenttype['slug'];
    }

    /**
     * Get the relations for one or more units of content, return the array with the taxonomy attached.
     *
     * @param array $content
     *
     * @return array $content
     */
    protected function getRelation($content) {

    }

    /**
     * Get the taxonomy for one or more units of content, return the array with the taxonomy attached.
     *
     * @param \Bolt\Legacy\Content[] $content
     *
     * @return array $content
     */
    protected function getTaxonomy($content) {

    }

    /**
     * Writes a content-changelog entry for a newly-created entry.
     *
     * @param string  $contenttype Slug of the record contenttype
     * @param integer $contentid   ID of the record
     * @param array   $content     Record values
     * @param string  $comment     Editor's comment
     */
    private function logInsert($contenttype, $contentid, $content, $comment = null) {
        $this->app['logger.change']->info(
            'Insert record',
            [
                'action' => 'INSERT',
                'contenttype' => $contenttype,
                'id' => $contentid,
                'new' => $content,
                'old' => null,
                'comment' => $comment,
            ]
        );
    }

    /**
     * Writes a content-changelog entry for an updated entry.
     * This function must be called *before* the actual update, because it
     * fetches the old content from the database.
     *
     * @param string  $contenttype Slug of the record contenttype
     * @param integer $contentid   ID of the record
     * @param array   $newContent  New record values
     * @param array   $oldContent  Old record values
     * @param string  $comment     Editor's comment
     */
    private function logUpdate($contenttype, $contentid, $newContent, $oldContent = null, $comment = null) {
        $this->app['logger.change']->info(
            'Update record',
            [
                'action' => 'UPDATE',
                'contenttype' => $contenttype,
                'id' => $contentid,
                'new' => $newContent,
                'old' => $oldContent,
                'comment' => $comment,
            ]
        );
    }

    /**
     * Writes a content-changelog entry for a deleted entry.
     * This function must be called *before* the actual update, because it.
     *
     * @param string  $contenttype Slug of the record contenttype
     * @param integer $contentid   ID of the record
     * @param array   $content     Record values
     * @param string  $comment     Editor's comment
     */
    private function logDelete($contenttype, $contentid, $content, $comment = null) {
        $this->app['logger.change']->info(
            'Delete record',
            [
                'action' => 'DELETE',
                'contenttype' => $contenttype,
                'id' => $contentid,
                'new' => null,
                'old' => $content,
                'comment' => $comment,
            ]
        );
    }

    /**
     * Get a valid array to commit.
     *
     * @param array $fieldvalues
     * @param array $contenttype
     *
     * @return array
     */
    private function getValidSaveData(array $fieldvalues, array $contenttype) {
        // Clean up fields, check unneeded columns.
        foreach (array_keys($fieldvalues) as $key) {
            if ($this->isValidColumn($key, $contenttype)) {
                if (is_string($fieldvalues[$key])) {
                    // Trim strings
                    $fieldvalues[$key] = trim($fieldvalues[$key]);
                } elseif (is_bool($fieldvalues[$key])) {
                    // Convert literal booleans to 0/1 to ensure cross-db consistency
                    $fieldvalues[$key] = (int) $fieldvalues[$key];
                }
            } else {
                // unset columns we don't need to store.
                unset($fieldvalues[$key]);
            }
        }

        return $fieldvalues;
    }

    /**
     * Decode search query into searchable parts.
     */
    private function decodeSearchQuery($q) {
        $words = preg_split('|[\r\n\t ]+|', trim($q));

        $words = array_map(
            function ($word) {
                return mb_strtolower($word);
            },
            $words
        );
        $words = array_filter(
            $words,
            function ($word) {
                return strlen($word) >= 2;
            }
        );

        return [
            'valid' => count($words) > 0,
            'in_q' => $q,
            'use_q' => implode(' ', $words),
            'words' => $words,
        ];
    }

    /**
     * Search through a single contenttype.
     *
     * Search, weigh and return the results.
     *
     * @param       $query
     * @param       $contenttype
     * @param       $fields
     * @param array $filter
     *
     * @return \Bolt\Legacy\Content
     */
    private function searchSingleContentType($query, $contenttype, $fields, array $filter = null) {
        // This could be even more configurable
        // (see also Content->getFieldWeights)
        $searchableTypes = ['text', 'textarea', 'html', 'markdown'];
        $table = $this->getContentTypeCollection($contenttype);

        // Build fields 'WHERE'
        $fieldsWhere = [];
        foreach ($fields as $field => $fieldconfig) {
            if (in_array($fieldconfig['type'], $searchableTypes)) {
                foreach ($query['words'] as $word) {
                    $fieldsWhere[] = sprintf('%s.%s LIKE %s', $table, $field, $this->app['db']->quote('%' . $word . '%'));
                }
            }
        }

        // make taxonomies work
        $taxonomytable = $this->getTablename('taxonomy');
        $taxonomies = $this->getContentTypeTaxonomy($contenttype);
        $tagsWhere = [];
        $tagsQuery = '';
        foreach ($taxonomies as $taxonomy) {
            if ($taxonomy['behaves_like'] == 'tags') {
                foreach ($query['words'] as $word) {
                    $tagsWhere[] = sprintf('%s.slug LIKE %s', $taxonomytable, $this->app['db']->quote('%' . $word . '%'));
                }
            }
        }
        // only add taxonomies if they exist
        if (!empty($taxonomies) && !empty($tagsWhere)) {
            $tagsQueryA = sprintf("%s.contenttype = '%s'", $taxonomytable, $contenttype);
            $tagsQueryB = implode(' OR ', $tagsWhere);
            $tagsQuery = sprintf(' OR (%s AND (%s))', $tagsQueryA, $tagsQueryB);
        }

        // Build filter 'WHERE"
        // @todo make relations work as well
        $filterWhere = [];
        if (!is_null($filter)) {
            foreach ($fields as $field => $fieldconfig) {
                if (isset($filter[$field])) {
                    $this->parseWhereParameter($table . '.' . $field, $filter[$field], $filterWhere);
                }
            }
        }

        // Build actual where
        $where = [];
        $where[] = sprintf("%s.status = 'published'", $table);
        $where[] = '(( ' . implode(' OR ', $fieldsWhere) . ' ) ' . $tagsQuery . ' )';
        $where = array_merge($where, $filterWhere);

        // Build SQL query
        $select = sprintf(
            'SELECT %s.id FROM %s LEFT JOIN %s ON %s.id = %s.content_id WHERE %s GROUP BY %s.id',
            $table,
            $table,
            $taxonomytable,
            $table,
            $taxonomytable,
            implode(' AND ', $where),
            $table
        );

        // Run Query
        $results = $this->app['db']->fetchAll($select);

        if (!empty($results)) {
            $ids = implode(' || ', util::array_pluck($results, 'id'));

            $results = $this->getContent($contenttype, ['id' => $ids, 'returnsingle' => false]);

            // Convert and weight
            foreach ($results as $result) {
                $result->weighSearchResult($query);
            }
        }

        return $results;
    }

    /**
     * Compare by search weights.
     *
     * Or fallback to dates or title
     *
     * @param \Bolt\Legacy\Content $a
     * @param \Bolt\Legacy\Content $b
     *
     * @return int
     */
    private function compareSearchWeights(Content $a, Content $b) {
        if ($a->getSearchResultWeight() > $b->getSearchResultWeight()) {
            return -1;
        }
        if ($a->getSearchResultWeight() < $b->getSearchResultWeight()) {
            return 1;
        }
        if ($a['datepublish'] > $b['datepublish']) {
            // later is more important
            return -1;
        }
        if ($a['datepublish'] < $b['datepublish']) {
            // earlier is less important
            return 1;
        }

        return strcasecmp($a['title'], $b['title']);
    }

    /**
     * Split into meta-parameters and contenttype parameters.
     *
     * This is tightly coupled to $this->getContent()
     *
     * @param array|string|null $inParameters
     *
     * @see $this->decodeContentQuery()
     */
    private function organizeQueryParameters($inParameters = null) {
        $ctypeParameters = [];
        $metaParameters = [];
        if (is_array($inParameters)) {
            foreach ($inParameters as $key => $value) {
                if (in_array($key, ['page', 'limit', 'offset', 'returnsingle', 'printquery', 'paging', 'order'])) {
                    $metaParameters[$key] = $value;
                } else {
                    $ctypeParameters[$key] = $value;
                }
            }
        }

        return [$metaParameters, $ctypeParameters];
    }

    /**
     * Decode a contenttypes argument from text.
     *
     * (entry,page) -> ['entry', 'page']
     * event -> ['event']
     *
     * @param string $text text with contenttypes
     *
     * @return array array with contenttype(slug)s
     */
    private function decodeContentTypesFromText($text) {
        $contenttypes = [];

        if ((substr($text, 0, 1) == '(') &&
            (substr($text, -1) == ')')
        ) {
            $contenttypes = explode(',', substr($text, 1, -1));
        } else {
            $contenttypes[] = $text;
        }

        $instance = $this;
        $contenttypes = array_map(
            function ($name) use ($instance) {
                $ct = $instance->getContentType($name);

                return $ct['slug'];
            },
            $contenttypes
        );

        return $contenttypes;
    }

    /**
     * Parse textquery into useable arguments.
     *
     * This is tightly coupled to $this->getContent()
     *
     * @see $this->decodeContentQuery()
     *
     * @param $textquery
     * @param array $decoded         a pre-set decoded array to fill
     * @param array $metaParameters  meta parameters
     * @param array $ctypeParameters contenttype parameters
     */
    private function parseTextQuery($textquery, array &$decoded, array &$metaParameters, array &$ctypeParameters) {
        // Our default callback
        $decoded['queries_callback'] = [$this, 'executeGetRequest'];

        // Some special cases, like 'entry/1' or 'page/about' need to be caught before further processing.
        if (preg_match('#^/?([a-z0-9_-]+)/([0-9]+)$#i', $textquery, $match)) {
            // like 'entry/12' or '/page/12345'
            $decoded['contenttypes'] = $this->decodeContentTypesFromText($match[1]);
            $decoded['return_single'] = true;
            $ctypeParameters['id'] = $match[2];
        } elseif (preg_match('#^/?([a-z0-9_(\),-]+)/search(/([0-9]+))?$#i', $textquery, $match)) {
            // like 'page/search or '(entry,page)/search'
            $decoded['contenttypes'] = $this->decodeContentTypesFromText($match[1]);
            $metaParameters['order'] = [$this, 'compareSearchWeights'];
            if (count($match) >= 3) {
                $metaParameters['limit'] = $match[3];
            }

            $decoded['queries_callback'] = [$this, 'executeGetContentSearch'];
        } elseif (preg_match('#^/?([a-z0-9_-]+)/([a-z0-9_-]+)$#i', $textquery, $match)) {
            // like 'page/lorem-ipsum-dolor' or '/page/home'
            $decoded['contenttypes'] = $this->decodeContentTypesFromText($match[1]);
            $decoded['return_single'] = true;
            $ctypeParameters['slug'] = $match[2];
        } elseif (preg_match('#^/?([a-z0-9_-]+)/(latest|first)/([0-9]+)$#i', $textquery, $match)) {
            // like 'page/latest/5'
            $decoded['contenttypes'] = $this->decodeContentTypesFromText($match[1]);
            if (!isset($metaParameters['order']) || $metaParameters['order'] === false) {
                $metaParameters['order'] = ($match[2] == 'latest' ? '-' : '+') . 'datepublish';
            }
            if (!isset($metaParameters['limit'])) {
                $metaParameters['limit'] = $match[3];
            }
        } elseif (preg_match('#^/?([a-z0-9_-]+)/random/([0-9]+)$#i', $textquery, $match)) {
            // like 'page/random/4'
            // unsupported
        } else {
            $decoded['contenttypes'] = $this->decodeContentTypesFromText($textquery);

            if (isset($ctypeParameters['id'])) {
                $decoded['return_single'] = true;
            }
        }

        // When using from the frontend, we assume (by default) that we only want published items,
        // unless something else is specified explicitly
        if (isset($this->app['end']) && $this->app['end'] == "frontend" && empty($ctypeParameters['status'])) {
            $ctypeParameters['status'] = "published";
        }

        if (isset($metaParameters['returnsingle'])) {
            $decoded['return_single'] = $metaParameters['returnsingle'];
            unset($metaParameters['returnsingle']);
        }
    }

    /**
     * Prepare decoded for actual use.
     *
     * This is tightly coupled to $this->getContent()
     *
     * @see $this->decodeContentQuery()
     *
     * @param $decoded
     * @param $metaParameters
     * @param $ctypeParameters
     */
    private function prepareDecodedQueryForUse(&$decoded, &$metaParameters, &$ctypeParameters) {
        // If there is only 1 contenttype we assume the where is NOT nested
        if (count($decoded['contenttypes']) == 1) {
            // So we need to add this nesting
            $ctypeParameters = [$decoded['contenttypes'][0] => $ctypeParameters];
        } else {
            // We need to set every non-contenttypeslug parameters to each individual contenttypes
            $globalParameters = [];
            foreach ($ctypeParameters as $key => $parameter) {
                if (!in_array($key, $decoded['contenttypes'])) {
                    $globalParameters[$key] = $parameter;
                }
            }
            foreach ($globalParameters as $key => $parameter) {
                unset($ctypeParameters[$key]);
                foreach ($decoded['contenttypes'] as $contenttype) {
                    if (!isset($ctypeParameters[$contenttype])) {
                        $ctypeParameters[$contenttype] = [];
                    }
                    if (!isset($ctypeParameters[$contenttype][$key])) {
                        $ctypeParameters[$contenttype][$key] = $parameter;
                    }
                }
            }

            // In this case query pagination never makes sense!
            $decoded['self_paginated'] = false;
        }

        if (($decoded['order_callback'] !== false) || ($decoded['return_single'] === true)) {
            // Callback sorting disables pagination
            $decoded['self_paginated'] = false;
        }

        if (!isset($metaParameters['order']) || $metaParameters['order'] === false) {
            if (count($decoded['contenttypes']) == 1) {
                if ($this->getContentTypeGrouping($decoded['contenttypes'][0])) {
                    $decoded['order_callback'] = [$this, 'groupingSort'];
                }
            }
        }

        if ($decoded['return_single']) {
            $metaParameters['limit'] = 1;
        } elseif (!isset($metaParameters['limit'])) {
            $metaParameters['limit'] = 9999;
        }
    }

    /**
     * Get the parameter for the 'order by' part of a query.
     *
     * This is tightly coupled to $this->getContent()
     *
     * @param array  $contenttype
     * @param string $orderValue
     *
     * @return string
     */
    private function decodeQueryOrder($contenttype, $orderValue) {
        $order = false;

        if (($orderValue === false) || ($orderValue === '')) {
            if ($this->isValidColumn($contenttype['sort'], $contenttype, true)) {
                $order = $contenttype['sort'];
            }
        } else {
            $parOrder = Str::makeSafe($orderValue);
            if ($parOrder == 'RANDOM') {
                // Unsupported
                return false;
            } elseif ($this->isValidColumn($parOrder, $contenttype, true)) {
                $order = $parOrder;
            }
        }

        return $order;
    }

    /**
     * Decode a content textquery
     *
     * This is tightly coupled to $this->getContent()
     *
     * @param string $textquery
     * @param array  $inParameters
     *
     * @internal param string $query the query (eg. page/about, entries/latest/5)
     * @internal param array  $parameters parameters to the query
     *
     * @return array decoded query, keys:
     *               contenttypes   - array, contenttypeslugs that will be returned
     *               return_single  - boolean, true if only 1 result should be returned
     *               self_paginated - boolean, true if already be paginated
     *               order_callback - callback, sort results post-hydration after everything is merged
     *               queries        - array of SQL query parts:
     *               * tablename    - tablename
     *               * contenttype  - contenttype array
     *               * query        - query string part
     *               * order        - order part
     *               * params       - bind-parameters
     *               parameters     - parameters to use after the queries
     */
    private function decodeContentQuery($textquery, $inParameters = null) {
        $decoded = [
            'contenttypes' => [],
            'return_single' => false,
            'self_paginated' => true,
            'order_callback' => false,
            'queries' => [],
            'parameters' => [],
            'hydrate' => true,
        ];

        list($metaParameters, $ctypeParameters) = $this->organizeQueryParameters($inParameters);

        $this->parseTextQuery($textquery, $decoded, $metaParameters, $ctypeParameters);

        // $decoded['contettypes'] gotten here
        // get page nr. from url if has
        $metaParameters['page'] = $this->decodePageParameter(implode('_', $decoded['contenttypes']));

        $this->prepareDecodedQueryForUse($decoded, $metaParameters, $ctypeParameters);

        $decoded['parameters'] = $metaParameters;

        // for all the non-reserved parameters that are fields or taxonomies, we assume people want to do a 'where'
        foreach ($ctypeParameters as $contenttypeslug => $actualParameters) {
            $contenttype = $this->getContentType($contenttypeslug);
            $collection = $this->getContentTypeCollection($contenttype);
            $resource = '';
            $where = [];
            $order = [];

            // Set the 'order', if specified in the meta_parameters.
            if (!empty($metaParameters['order'])) {
                $order[] = $metaParameters['order'];
            }

            if ($contenttype === false) {
                /*
                 * We were logging here, but a couple of places like
                 * TwigExtension::menuHelper() would trigger lots of hits,
                 * filling logs and impacting performance as a result.
                 * @see #1799 https://github.com/bolt/bolt/issues/1799
                 *
                 * When we refactor we need to address the callers, as this is a
                 * valid error state.
                 */
                continue;
            }

            if (is_array($actualParameters)) {
                foreach ($actualParameters as $key => $value) {
                    if ($key == 'order') {
                        $orderValue = $this->decodeQueryOrder($contenttype, $value);

                        if ($orderValue !== false) {
                            $order[] = $orderValue;
                        }

                        continue;
                    }

                    if ($key == 'filter' && !empty($value)) {
                        $where['search'] = $value;

                        continue;
                    }

                    // build OR parts if key contains "|||"
                    if (strpos($key, " ||| ") !== false) {
                        // Unsupported
                        continue;
                    }

                    if ($key == 'id' && !empty($value)) {
                        $resource = $value;

                        continue;
                    }

                    // for all the parameters that are fields
                    if (in_array($key, $contenttype['fields']) || in_array($key, Content::getBaseColumns())) {
                        $fieldconfig = $contenttype['fields'][$key];

                        if (!empty($fieldconfig['blimp_field'])) {
                            $key = $fieldconfig['blimp_field'];
                        }

                        $this->parseWhereParameter($key, $value, $fieldconfig['type'], $where);

                        continue;
                    }

                    // for all the  parameters that are taxonomies
                    if (array_key_exists($key, $this->getContentTypeTaxonomy($contenttype['slug']))) {
                        // Unsupported
                        continue;
                    }
                }
            }

            if (count($order) == 0) {
                $order[] = $this->decodeQueryOrder($contenttype, false) ?: '-created';
            }

            $query = [
                'collection' => $collection,
                'resource' => $resource,
                'contenttype' => $contenttype,
                'query' => $where,
                'order' => implode(',', $order),
                'params' => [],
            ];

            $decoded['queries'][] = $query;

            if (isset($inParameters['hydrate'])) {
                $decoded['hydrate'] = $inParameters['hydrate'];
            }
        }

        return $decoded;
    }

    /**
     * Run existence and perform publish/depublishes.
     *
     * @param array ContentType slugs to check
     *
     * @return mixed false, if any table doesn't exist
     *               true, if all is fine
     */
    private function runContenttypeChecks(array $contenttypes) {
        $checkedcontenttype = [];

        foreach ($contenttypes as $contenttypeslug) {

            // Make sure we do this only once per contenttype
            if (isset($checkedcontenttype[$contenttypeslug])) {
                continue;
            }

            $contenttype = $this->getContentType($contenttypeslug);
            $tablename = $this->getContentTypeCollection($contenttype);

            // If the table doesn't exist (yet), return false.
            if (!$this->tableExists($tablename)) {
                return false;
            }

            // Check if we need to 'publish' any 'timed' records, or 'depublish' any expired records.
            $this->publishTimedRecords($contenttype);
            $this->depublishExpiredRecords($contenttype);

            // "mark" this one as checked.
            $checkedcontenttype[$contenttypeslug] = true;
        }

        return true;
    }

    /**
     * Hydrate database rows into objects.
     *
     * @param array|string $contenttype
     * @param array        $rows
     * @param boolean      $getTaxoAndRel
     *
     * @throws \Exception
     *
     * @return array
     */
    private function hydrateRows($contenttype, $rows, $getTaxoAndRel = true) {
        // Make sure content is set, and all content has information about its contenttype
        $objects = [];
        foreach ($rows as $row) {
            $objects[$row['id']] = $this->getContentObject($contenttype, $row);
        }

        if ($getTaxoAndRel) {
            // Make sure all content has their taxonomies and relations
            $this->getTaxonomy($objects);
            $this->getRelation($objects);
        }

        return $objects;
    }

    /**
     * Check if a given name is a valid column, and if it can be used in queries.
     *
     * @param string  $name
     * @param array   $contenttype
     * @param boolean $allowVariants
     *
     * @return boolean
     */
    private function isValidColumn($name, $contenttype, $allowVariants = false) {
        // Strip the minus in '-title' if allowed.
        if ($allowVariants) {
            if ((strlen($name) > 0) && ($name[0] == "-")) {
                $name = substr($name, 1);
            }
        }

        // Check if the $name is in the contenttype's fields.
        if (isset($contenttype['fields'][$name])) {
            return true;
        }

        if (in_array($name, Content::getBaseColumns())) {
            return true;
        }

        return false;
    }

    /**
     * Helper function for sorting Records of content that have a Grouping.
     *
     * @param Content $a
     * @param Content $b
     *
     * @return int
     */
    private function groupingSort(Content $a, Content $b) {
        // Same group, sort within group.
        if ($a->group['slug'] == $b->group['slug']) {
            if (!empty($a->sortorder) || !empty($b->sortorder)) {
                if (!isset($a->sortorder)) {
                    return 1;
                } elseif (!isset($b->sortorder)) {
                    return -1;
                } else {
                    return ($a->sortorder < $b->sortorder) ? -1 : 1;
                }
            }

            if (!empty($a->contenttype['sort'])) {
                // Same group, so we sort on contenttype['sort']
                list($secondSort, $order) = $this->getSortOrder($a->contenttype['sort']);

                $vala = strtolower($a->values[$secondSort]);
                $valb = strtolower($b->values[$secondSort]);

                if ($vala == $valb) {
                    return 0;
                } else {
                    $result = ($vala < $valb) ? -1 : 1;
                    // if $order is false, the 'getSortOrder' indicated that we used something like '-id'.
                    // So, perhaps we need to inverse the result.
                    return $order ? $result : -$result;
                }
            }
        }
        // Otherwise, sort based on the group. Or, more specifically, on the index of
        // the item in the group's taxonomy definition.
        return ($a->group['index'] < $b->group['index']) ? -1 : 1;
    }

    /**
     * Helper function to set the proper 'where' parameter,
     * when getting values like '<2012' or '!bob'.
     *
     * @param string $key
     * @param string $value
     * @param mixed  $fieldtype
     *
     * @return string
     */
    private function parseWhereParameter($key, $value, $fieldtype, &$where) {
        $value = trim($value);

        // check if we need to split.
        if (strpos($value, " || ") !== false) {
            // Not supported

            return;
        } elseif (strpos($value, " && ") !== false) {
            // Not supported

            return;
        }

        // Set the correct operator for the where clause
        $operator = "";

        $first = substr($value, 0, 1);

        if ($first == "!") {
            $operator = "ne/";
            $value = substr($value, 1);
        } elseif (substr($value, 0, 2) == "<=") {
            $operator = "lte/";
            $value = substr($value, 2);
        } elseif (substr($value, 0, 2) == ">=") {
            $operator = "gte/";
            $value = substr($value, 2);
        } elseif ($first == "<") {
            $operator = "lt/";
            $value = substr($value, 1);
        } elseif ($first == ">") {
            $operator = "gt/";
            $value = substr($value, 1);
        } elseif ($first == "%" || substr($value, -1) == "%") {
            $operator = "m/";
        }

        // Use strtotime to allow selections like "< last monday" or "this year"
        if (in_array($fieldtype, ['date', 'datetime']) && ($timestamp = strtotime($value)) !== false) {
            $value = date('Y-m-d H:i:s', $timestamp);
        }

        $where[$key] = $operator . $value . ($operator == "m/" ? '/i' : '');
    }
}
