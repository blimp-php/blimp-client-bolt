<?php
namespace Bolt\Extension\Blimp\Client\Controller\Backend;

use Bolt\Controller\Backend\BackendBase;
use Bolt\Translation\Translator as Trans;
use Silex\ControllerCollection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use Bolt\Content;
use Bolt\Pager;

/**
 * Backend controller for record manipulation routes.
 *
 * @author Nelson Antunes <nelson.antunes@gmail.com>
 */
class BlimpRecords extends BackendBase
{
    protected function addRoutes(ControllerCollection $c)
    {
        $c->method('GET|POST');

        $c->get('/blimp-list/{contenttypeslug}', 'blimp-list')->bind('blimp-list');

        $c->get('/blimp-delete/{contenttypeslug}/{id}', 'blimp-delete')
            ->bind('blimp-delete');

        $c->match('/blimp-edit/{contenttypeslug}/{id}', 'blimp-edit')
            ->bind('blimp-edit')
            ->value('id', '');

        $c->post('/blimp/{action}/{contenttypeslug}/{id}', 'blimp-action')
            ->bind('blimp-action');
    }

    /**
     * Content list page.
     *
     * @param Request $request         The Symfony Request
     * @param string  $contenttypeslug The content type slug
     *
     * @return \Bolt\Response\BoltResponse|\Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function overview(Request $request, $contenttypeslug)
    {
        // Order has to be set carefully. Either set it explicitly when the user
        // sorts, or fall back to what's defined in the contenttype. Except for
        // a ContentType that has a "grouping taxonomy", as that should override
        // it. That exception state is handled by the query OrderHandler.
        $contenttype = $this->getContentType($contenttypeslug);

        if (!$contenttype) {
            $this->flashes()->error(Trans::__('Attempt to list invalid Contenttype.'));

            return $this->redirectToRoute('dashboard');
        }

        $contentparameters = ['paging' => true, 'hydrate' => true];
        $contentparameters['order'] = $request->query->get('order', $contenttype['sort']);
        $contentparameters['page'] = $request->query->get('page');

        $filter = [];
        if ($request->query->get('filter')) {
            $contentparameters['filter'] = $request->query->get('filter');
            $filter[] = $request->query->get('filter');
        }

        // Set the amount of items to show per page.
        if (!empty($contenttype['recordsperpage'])) {
            $contentparameters['limit'] = $contenttype['recordsperpage'];
        } else {
            $contentparameters['limit'] = $this->getOption('general/recordsperpage');
        }

        // Perhaps also filter on taxonomies
        foreach (array_keys($this->getOption('taxonomy', [])) as $taxonomykey) {
            if ($request->query->get('taxonomy-' . $taxonomykey)) {
                $contentparameters[$taxonomykey] = $request->query->get('taxonomy-' . $taxonomykey);
                $filter[] = $request->query->get('taxonomy-' . $taxonomykey);
            }
        }

        $multiplecontent = $this->getContent($contenttypeslug, $contentparameters);

        $context = [
            'contenttype'     => $contenttype,
            'multiplecontent' => $multiplecontent,
            'filter'          => $filter,
            'permissions'     => $this->getContentTypeUserPermissions($contenttypeslug, $this->users()->getCurrentUser())
        ];

        return $this->render('blimp-client-bolt/list/overview.twig', $context);
    }

    /**
     * Delete a record.
     *
     * @param Request $request         The Symfony Request
     * @param string  $contenttypeslug
     * @param integer $id
     *
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function delete(Request $request, $contenttypeslug, $id)
    {
        $ids = explode(',', $id);
        $contenttype = $this->getContentType($contenttypeslug);

        foreach ($ids as $id) {
            $content = $this->getContent($contenttype['slug'], ['id' => $id, 'status' => '!undefined']);
            $title = $content->getTitle();

            if ($this->checkAntiCSRFToken() && $this->app['storage']->deleteContent($contenttypeslug, $id)) {
                $this->flashes()->info(Trans::__("Content '%title%' has been deleted.", ['%title%' => $title]));
            } else {
                $this->flashes()->info(Trans::__("Content '%title%' could not be deleted.", ['%title%' => $title]));
            }
        }

        return $this->redirectToRoute('remote-overview', ['contenttypeslug' => $contenttypeslug]);
    }

    /**
     * Edit a record, or create a new one.
     *
     * @param Request $request         The Symfony Request
     * @param string  $contenttypeslug The content type slug
     * @param integer $id              The content ID
     *
     * @return \Bolt\Response\BoltResponse|\Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function edit(Request $request, $contenttypeslug, $id)
    {
        // Is the record new or existing
        $new = empty($id) ?: false;

        // Set the editreferrer in twig if it was not set yet.
        $this->setEditReferrer($request);

        // Get the Contenttype obejct
        $contenttype = $this->getContentType($contenttypeslug);
dump($contenttypeslug);die;
        // Save the POSTed record
        if ($request->isMethod('POST')) {
            $formValues = $request->request->all();
            $returnTo = $request->get('returnto');
            $editReferrer = $request->get('editreferrer');

            return $this->recordModifier()->handleSaveRequest($formValues, $contenttype, $id, $new, $returnTo, $editReferrer);
        }

        if ($new) {
            $content = $this->app['storage']->getEmptyContent($contenttypeslug);
        } else {
            $content = $this->getContent($contenttypeslug, ['id' => $id]);

            if (empty($content)) {
                // Record not found, advise and redirect to the dashboard
                $this->flashes()->error(Trans::__('contenttypes.generic.not-existing', ['%contenttype%' => $contenttypeslug]));

                return $this->redirectToRoute('dashboard');
            }
        }

        // We're doing a GET
        $duplicate = $request->query->get('duplicate', false);
        $context = $this->recordModifier()->handleEditRequest($content, $contenttype, $id, $new, $duplicate);

        return $this->render('editcontent/editcontent.twig', $context);
    }

    /**
     * Perform an action on a Contenttype record.
     *
     * @param Request $request         The Symfony Request
     * @param string  $contenttypeslug The content type slug
     * @param integer $id              The content ID
     *
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function modify(Request $request, $action, $contenttypeslug, $id)
    {
        if ($action === 'delete') {
            return $this->delete($request, $contenttypeslug, $id);
        }

        // This shoudln't happen
        if (!$this->getContentType($contenttypeslug)) {
            $this->flashes()->error(Trans::__('Attempt to modify invalid Contenttype.'));

            return $this->redirectToRoute('dashboard');
        }

        // Map actions to new statuses
        $actionStatuses = [
            'held'    => 'held',
            'publish' => 'published',
            'draft'   => 'draft',
        ];
        // Map actions to requred permission
        $actionPermissions = [
            'publish' => 'publish',
            'held'    => 'depublish',
            'draft'   => 'depublish',
        ];

        if (!isset($actionStatuses[$action])) {
            $this->flashes()->error(Trans::__('No such action for content.'));

            return $this->redirectToRoute('overview', ['contenttypeslug' => $contenttypeslug]);
        }

        $newStatus = $actionStatuses[$action];
        $content = $this->getContent("$contenttypeslug/$id");
        $title = $content->getTitle();

        if (!$this->isAllowed("contenttype:$contenttypeslug:{$actionPermissions[$action]}:$id") ||
        !$this->users()->isContentStatusTransitionAllowed($content['status'], $newStatus, $contenttypeslug, $id)) {
            $this->flashes()->error(Trans::__('You do not have the right privileges to %ACTION% that record.', ['%ACTION%' => $actionPermissions[$action]]));

            return $this->redirectToRoute('overview', ['contenttypeslug' => $contenttypeslug]);
        }

        if ($this->app['storage']->updateSingleValue($contenttypeslug, $id, 'status', $newStatus)) {
            $this->flashes()->info(Trans::__("Content '%title%' has been changed to '%newStatus%'", ['%title%' => $title, '%newStatus%' => $newStatus]));
        } else {
            $this->flashes()->info(Trans::__("Content '%title%' could not be modified.", ['%title%' => $title]));
        }

        return $this->redirectToRoute('overview', ['contenttypeslug' => $contenttypeslug]);
    }

    /**
     * Set the editreferrer in twig if it was not set yet.
     *
     * @param Request $request
     *
     * @return void
     */
    private function setEditReferrer(Request $request)
    {
        $tmp = parse_url($request->server->get('HTTP_REFERER'));

        $tmpreferrer = $tmp['path'];
        if (!empty($tmp['query'])) {
            $tmpreferrer .= '?' . $tmp['query'];
        }

        if (strpos($tmpreferrer, '/overview/') !== false || ($tmpreferrer === $this->resources()->getUrl('bolt'))) {
            $this->app['twig']->addGlobal('editreferrer', $tmpreferrer);
        }
    }

    /**
     * @return \Bolt\Storage\RecordModifier
     */
    protected function recordModifier()
    {
        return $this->app['storage.record_modifier'];
    }

    public function getContent($textquery, $parameters = '', &$pager = [], $whereparameters = [])
    {
        // Start the 'stopwatch' for the profiler.
        $this->app['stopwatch']->start('bolt.getcontent', 'blimp');

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
            if (util::array_first_key($results)) {
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
                'for'          => $pagerName,
                'count'        => $totalResults,
                'totalpages'   => ceil($totalResults / $decoded['parameters']['limit']),
                'current'      => $decoded['parameters']['page'],
                'showing_from' => ($decoded['parameters']['page'] - 1) * $decoded['parameters']['limit'] + 1,
                'showing_to'   => ($decoded['parameters']['page'] - 1) * $decoded['parameters']['limit'] + count($results)
            ];
            $this->setPager($pagerName, $pager);
            $this->app['twig']->addGlobal('pager', $this->getPager());
        }

        $this->app['stopwatch']->stop('bolt.getcontent');

        return $results;
    }
    private function decodeContentQuery($textquery, $inParameters = null)
    {
        $decoded = [
            'contenttypes'   => [],
            'return_single'  => false,
            'self_paginated' => true,
            'order_callback' => false,
            'queries'        => [],
            'parameters'     => [],
            'hydrate'        => true,
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
            $tablename = $this->getContenttypeTablename($contenttype);
            $where = [];
            $order = [];

            // Set the 'order', if specified in the meta_parameters.
            if (!empty($metaParameters['order'])) {
                $order[] = $this->getEscapedSortorder($metaParameters['order'], false);
            }

            $query = [
                'tablename'   => $tablename,
                'contenttype' => $contenttype,
                'from'        => sprintf('FROM %s', $tablename),
                'where'       => '',
                'order'       => '',
                'params'      => []
            ];

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
                // Set the 'FROM' part of the query, without the LEFT JOIN (i.e. no taxonomies..)
                foreach ($actualParameters as $key => $value) {
                    if ($key == 'order') {
                        $orderValue = $this->decodeQueryOrder($contenttype, $value);
                        if ($orderValue !== false) {
                            $order[] = $orderValue;
                        }
                        continue;
                    }

                    if ($key == 'filter' && !empty($value)) {
                        $filterWhere = [];
                        foreach ($contenttype['fields'] as $name => $fieldconfig) {
                            if (in_array($fieldconfig['type'], ['text', 'textarea', 'html', 'markdown'])) {
                                $filterWhere[] = sprintf(
                                    '%s.%s LIKE %s',
                                    $tablename,
                                    $name,
                                    $this->app['db']->quote('%' . $value . '%')
                                );
                            }
                        }
                        if (count($filterWhere) > 0) {
                            $where[] = '(' . implode(' OR ', $filterWhere) . ')';
                        }
                        continue;
                    }

                    // build OR parts if key contains "|||"
                    if (strpos($key, " ||| ") !== false) {
                        $keyParts = explode(" ||| ", $key);
                        $valParts = explode(" ||| ", $value);
                        $orPart = '( ';
                        $countParts = count($keyParts);
                        for ($i = 0; $i < $countParts; $i++) {
                            if (in_array($keyParts[$i], $this->getContentTypeFields($contenttype['slug'])) ||
                                in_array($keyParts[$i], Content::getBaseColumns())) {
                                $rkey = $tablename . '.' . $keyParts[$i];
                                $fieldtype = $this->getContentTypeFieldType($contenttype['slug'], $keyParts[$i]);
                                $orPart .= ' (' . $this->parseWhereParameter($rkey, $valParts[$i], $fieldtype) . ') OR ';
                            }
                        }
                        if (strlen($orPart) > 2) {
                            $where[] = substr($orPart, 0, -4) . ') ';
                        }
                    }

                    // for all the parameters that are fields
                    if (in_array($key, $this->getContentTypeFields($contenttype['slug'])) ||
                        in_array($key, Content::getBaseColumns())
                    ) {
                        $rkey = $tablename . '.' . $key;
                        $fieldtype = $this->getContentTypeFieldType($contenttype['slug'], $key);
                        $where[] = $this->parseWhereParameter($rkey, $value, $fieldtype);
                    }

                    // for all the  parameters that are taxonomies
                    if (array_key_exists($key, $this->getContentTypeTaxonomy($contenttype['slug']))) {

                        // check if we're trying to use "!" as a way of 'not'. If so, we need to do a 'NOT IN', instead
                        // of 'IN'. And, the parameter in the subselect needs to be without "!" as a consequence.
                        if (strpos($value, "!") !== false) {
                            $notin = "NOT ";
                            $value = str_replace("!", "", $value);
                        } else {
                            $notin = "";
                        }

                        // Set the extra '$where', with subselect for taxonomies.
                        $where[] = sprintf(
                            '%s %s IN (SELECT content_id AS id FROM %s where %s AND ( %s OR %s ) AND %s)',
                            $this->app['db']->quoteIdentifier('id'),
                            $notin,
                            $this->getTablename('taxonomy'),
                            $this->parseWhereParameter($this->getTablename('taxonomy') . '.taxonomytype', $key),
                            $this->parseWhereParameter($this->getTablename('taxonomy') . '.slug', $value),
                            $this->parseWhereParameter($this->getTablename('taxonomy') . '.name', $value),
                            $this->parseWhereParameter($this->getTablename('taxonomy') . '.contenttype', $contenttype['slug'])
                        );
                    }
                }
            }

            if (count($order) == 0) {
                $order[] = $this->decodeQueryOrder($contenttype, false) ?: 'datepublish DESC';
            }

            if (count($where) > 0) {
                $query['where'] = sprintf('WHERE (%s)', implode(' AND ', $where));
            }
            if (count($order) > 0) {
                $order = implode(', ', $order);
                if (!empty($order)) {
                    $query['order'] = sprintf('ORDER BY %s', $order);
                }
            }

            $decoded['queries'][] = $query;

            if (isset($inParameters['hydrate'])) {
                $decoded['hydrate'] = $inParameters['hydrate'];
            }
        }

        return $decoded;
    }
    private function organizeQueryParameters($inParameters = null)
    {
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
    private function parseTextQuery($textquery, array &$decoded, array &$metaParameters, array &$ctypeParameters)
    {
        // Our default callback
        $decoded['queries_callback'] = [$this, 'executeGetContentQueries'];

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
                $metaParameters['order'] = 'datepublish ' . ($match[2] == 'latest' ? 'DESC' : 'ASC');
            }
            if (!isset($metaParameters['limit'])) {
                $metaParameters['limit'] = $match[3];
            }
        } elseif (preg_match('#^/?([a-z0-9_-]+)/random/([0-9]+)$#i', $textquery, $match)) {
            // like 'page/random/4'
            $decoded['contenttypes'] = $this->decodeContentTypesFromText($match[1]);
            $dboptions = $this->app['config']->getDBoptions();
            $metaParameters['order'] = $dboptions['randomfunction']; // 'RAND()' or 'RANDOM()'
            if (!isset($metaParameters['limit'])) {
                $metaParameters['limit'] = $match[2];
            }
        } else {
            $decoded['contenttypes'] = $this->decodeContentTypesFromText($textquery);

            if (isset($ctypeParameters['id']) && (is_numeric($ctypeParameters['id']))) {
                $decoded['return_single'] = true;
            }
        }

        // When using from the frontend, we assume (by default) that we only want published items,
        // unless something else is specified explicitly
        if (isset($this->app['end']) && $this->app['end'] != "backend" && empty($ctypeParameters['status'])) {
            $ctypeParameters['status'] = "published";
        }

        if (isset($metaParameters['returnsingle'])) {
            $decoded['return_single'] = $metaParameters['returnsingle'];
            unset($metaParameters['returnsingle']);
        }
    }
    private function decodeContentTypesFromText($text)
    {
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
    protected function decodePageParameter($context = '')
    {
        $param = Pager::makeParameterId($context);
        /* @var $query \Symfony\Component\HttpFoundation\ParameterBag */
        $query = $this->app['request']->query;
        $page = ($query) ? $query->get($param, $query->get('page', 1)) : 1;

        return $page;
    }
    private function prepareDecodedQueryForUse(&$decoded, &$metaParameters, &$ctypeParameters)
    {
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
    public function getContentTypeGrouping($contenttypeslug)
    {
        $grouping = false;
        $taxonomy = $this->getContentTypeTaxonomy($contenttypeslug);
        foreach ($taxonomy as $tax) {
            if ($tax['behaves_like'] === 'grouping') {
                $grouping = $tax['slug'];
                break;
            }
        }

        return $grouping;
    }
    public function getContentTypeTaxonomy($contenttypeslug)
    {
        $contenttype = $this->getContentType($contenttypeslug);

        if (empty($contenttype['taxonomy'])) {
            return [];
        } else {
            $taxokeys = $contenttype['taxonomy'];

            $taxonomy = [];

            foreach ($taxokeys as $key) {
                if ($this->app['config']->get('taxonomy/' . $key)) {
                    $taxonomy[$key] = $this->app['config']->get('taxonomy/' . $key);
                }
            }

            return $taxonomy;
        }
    }
    public function getContenttypeTablename($contenttype)
    {
        if (is_string($contenttype)) {
            $contenttype = $this->getContentType($contenttype);
        }

        return $this->getTablename($contenttype['tablename']);
    }
    public function getTablename($name)
    {
        if ($this->prefix === null) {
            $this->prefix = $this->app['config']->get('general/database/prefix', 'bolt_');
        }

        $name = str_replace('-', '_', $this->app['slugify']->slugify($name));
        $tablename = sprintf('%s%s', $this->prefix, $name);

        return $tablename;
    }
    public function getContentTypeFields($contenttypeslug)
    {
        $contenttype = $this->getContentType($contenttypeslug);

        if (empty($contenttype['fields'])) {
            return [];
        } else {
            return array_keys($contenttype['fields']);
        }
    }
    private function decodeQueryOrder($contenttype, $orderValue)
    {
        $order = false;

        if (($orderValue === false) || ($orderValue === '')) {
            if ($this->isValidColumn($contenttype['sort'], $contenttype, true)) {
                $order = $this->getEscapedSortorder($contenttype['sort'], false);
            }
        } else {
            $parOrder = Str::makeSafe($orderValue);
            if ($parOrder == 'RANDOM') {
                $dboptions = $this->app['db']->getParams();
                $order = $dboptions['randomfunction'];
            } elseif ($this->isValidColumn($parOrder, $contenttype, true)) {
                $order = $this->getEscapedSortorder($parOrder, false);
            }
        }

        return $order;
    }
    private function isValidColumn($name, $contenttype, $allowVariants = false)
    {
        // Strip the minus in '-title' if allowed.
        if ($allowVariants) {
            if ((strlen($name) > 0) && ($name[0] == "-")) {
                $name = substr($name, 1);
            }
            $name = $this->getFieldName($name);
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
    private function getFieldName($name)
    {
        return preg_replace("/ (desc|asc)$/i", "", $name);
    }
    private function groupingSort(Content $a, Content $b)
    {
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
    protected function executeGetContentQueries($decoded)
    {
        // Perform actual queries and hydrate
        $totalResults = false;
        $results = false;
        foreach ($decoded['queries'] as $query) {
            $statement = sprintf(
                'SELECT %s.* %s %s %s',
                $query['tablename'],
                $query['from'],
                $query['where'],
                $query['order']
            );

            if ($decoded['self_paginated'] === true) {
                // self pagination requires an extra query to return the actual number of results
                if ($decoded['return_single'] === false) {
                    // $countStatement = sprintf(
                    //     'SELECT COUNT(*) as count %s %s',
                    //     $query['from'],
                    //     $query['where']
                    // );
                    // $countRow = $this->app['db']->executeQuery($countStatement)->fetch();
                    // $totalResults = $countRow['count'];
                }

                if (isset($decoded['parameters']['paging']) && $decoded['parameters']['paging'] === true) {
                    $offset = ($decoded['parameters']['page'] - 1) * $decoded['parameters']['limit'];
                } else {
                    $offset = null;
                }
                $limit = $decoded['parameters']['limit'];

                // @todo this will fail when actually using params on certain databases
                // $statement = $this->app['db']->getDatabasePlatform()->modifyLimitQuery($statement, $limit, $offset);
            } elseif (!empty($decoded['parameters']['limit'])) {
                // If we're not paging, but we _did_ provide a limit.
                $limit = $decoded['parameters']['limit'];
                $statement = $this->app['db']->getDatabasePlatform()->modifyLimitQuery($statement, $limit);
            }

            if (!empty($decoded['parameters']['printquery'])) {
                // @todo formalize this
                echo nl2br(htmlentities($statement));
            }

            $res = $this->app['blimp_client.request']('GET', '/reports');
            $rows = $res['data']['elements'];
            $totalResults = $res['data']['count'];
            // $rows = $this->app['db']->fetchAll($statement, $query['params']);

            // Convert the row 'arrays' into Content objects.
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
    private function hydrateRows($contenttype, $rows, $getTaxoAndRel = true)
    {
        // Make sure content is set, and all content has information about its contenttype
        $objects = [];
        foreach ($rows as $row) {
            $objects[$row['id']] = $this->getContentObject($contenttype, $row);
        }

        if ($getTaxoAndRel) {
            // Make sure all content has their taxonomies and relations
            // $this->getTaxonomy($objects);
            // $this->getRelation($objects);
        }

        return $objects;
    }
    public function getContentObject($contenttype, $values = [])
    {
        // Make sure $contenttype is an array, and not just the slug.
        if (!is_array($contenttype)) {
            $contenttype = $this->getContentType($contenttype);
        }

        // If the contenttype has a 'class' specified, and the class exists,
        // Initialize the content as an object of that class.
        if (!empty($contenttype['class']) && class_exists($contenttype['class'])) {
            $content = new $contenttype['class']($this->app, $contenttype, $values);

            // Check if the class actually extends Content.
            if (!($content instanceof Content)) {
                throw new \Exception($contenttype['class'] . ' does not extend \\Bolt\\Content.');
            }
        } else {
            $content = new Content($this->app, $contenttype, $values);
        }

        return $content;
    }

        /** @var array */
    protected static $pager = [];

    public function setPager($name, $pager)
    {
        static::$pager[$name] = ($pager instanceof Pager) ? $pager : new Pager($pager, $this->app);

        return $this;
    }

    /**
     * Getter of a pager element. Pager can hold a paging snapshot map.
     *
     * @param string $name Optional name of a pager element. Whole pager map returns if no name given.
     *
     * @return array
     */
    public function &getPager($name = null)
    {
        if ($name) {
            if (array_key_exists($name, static::$pager)) {
                return static::$pager[$name];
            } else {
                return false;
            }
        } else {
            return static::$pager;
        }
    }

    public function isEmptyPager()
    {
        return (count(static::$pager) === 0);
    }

    public function getContentType($contenttypeslug)
    {
        $contenttypeslug = $this->app['slugify']->slugify($contenttypeslug);

        // Return false if empty, can't find it.
        if (empty($contenttypeslug)) {
            return false;
        }

        $res = $this->app['blimp_client.request']('GET', '/types/' . $contenttypeslug);

        if ($res['status'] == 200) {
            $contenttype = $res['data'];
        }

        if (!empty($contenttype)) {
            return $contenttype;
        } else {
            return false;
        }
    }
}
