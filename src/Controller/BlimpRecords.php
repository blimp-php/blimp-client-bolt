<?php
namespace Bolt\Extension\Blimp\Client\Controller;

use Bolt\Controller\Backend\Records;
use Silex\ControllerCollection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Bolt\Translation\Translator as Trans;

/**
 * Backend controller for record manipulation routes.
 *
 * @author Nelson Antunes <nelson.antunes@gmail.com>
 */
class BlimpRecords extends Records {
    protected function addRoutes(ControllerCollection $c) {
        $c->method('GET|POST');

        $c->get('/list/{contenttypeslug}', 'overview')
            ->bind('overview');

        $c->match('/add-edit/{contenttypeslug}/{id}', 'edit')
            ->bind('editcontent')
            ->value('id', '');

        $c->get('/delete/{contenttypeslug}/{id}', 'delete')
            ->bind('deletecontent');

        $c->post('/content-{action}/{contenttypeslug}/{id}', 'modify')
            ->bind('contentaction');

        $c->get('/relatedto/{contenttypeslug}/{id}', 'related')
            ->bind('relatedto');
    }

    public function getContent($textquery, $parameters = '', &$pager = [], $whereparameters = []) {
        $contenttype = $this->getContentType($textquery);

        if (empty($contenttype['blimp_mode']) || $contenttype['blimp_mode'] !== 'remote') {
            return $this->app['storage']->getContent($textquery, $parameters, $pager, $whereparameters);
        }

        return $this->app['blimp_client.storage']->getContent($textquery, $parameters, $pager, $whereparameters);
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
    public function delete(Request $request, $contenttypeslug, $id) {
        $contenttype = $this->getContentType($contenttypeslug);

        if (empty($contenttype['blimp_mode']) || $contenttype['blimp_mode'] === 'local') {
            return parent::delete($request, $contenttypeslug, $id);
        }

        $ids = explode(',', $id);

        foreach ($ids as $id) {
            $content = $this->getContent($contenttype['slug'], ['id' => $id]);

            if ($contenttype['blimp_mode'] === 'sync' && $content['status'] == 'published') {
                $this->modify($request, 'held', $contenttypeslug, $id);
                continue;
            }

            if (!empty($content)) {
                $title = $content->getTitle();

                if (!$this->isAllowed("contenttype:$contenttypeslug:delete:$id")) {
                    $this->flashes()->error(Trans::__('Permission denied', []));
                } elseif ($this->checkAntiCSRFToken() && $this->app['blimp_client.storage']->deleteContent($contenttypeslug, $id)) {
                    $this->flashes()->info(Trans::__("Content '%title%' has been deleted.", ['%title%' => $title]));
                } else {
                    $this->flashes()->info(Trans::__("Content '%title%' could not be deleted.", ['%title%' => $title]));
                }
            }
        }

        return $this->redirectToRoute('overview', ['contenttypeslug' => $contenttypeslug]);
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
        $contenttype = $this->getContentType($contenttypeslug);

        if (empty($contenttype['blimp_mode']) || $contenttype['blimp_mode'] === 'local') {
            return parent::modify($request, $action, $contenttypeslug, $id);
        }

        if ($action === 'held') {
            return parent::modify($request, $action, $contenttypeslug, $id);
        }

        if ($action === 'delete') {
            return $this->delete($request, $contenttypeslug, $id);
        }

        if ($contenttype['blimp_mode'] === 'sync') {
            // Map actions to new statuses
            $actionStatuses = [
                'publish' => 'published'
            ];

            // Map actions to requred permission
            $actionPermissions = [
                'publish' => 'publish'
            ];
        }

        if (!isset($actionStatuses[$action])) {
            $this->flashes()->error(Trans::__('No such action for content.'));

            return $this->redirectToRoute('overview', ['contenttypeslug' => $contenttypeslug]);
        }

        $newStatus = $actionStatuses[$action];
        $content = $this->getContent("$contenttypeslug/$id");
        if(empty($content)) {
            $this->flashes()->error(Trans::__('No such action for content.'));

            return $this->redirectToRoute('overview', ['contenttypeslug' => $contenttypeslug]);
        }

        $title = $content->getTitle();

        if (!$this->isAllowed("contenttype:$contenttypeslug:{$actionPermissions[$action]}:$id") || !$this->users()->isContentStatusTransitionAllowed($content['status'], $newStatus, $contenttypeslug, $id)) {
            $this->flashes()->error(Trans::__('You do not have the right privileges to %ACTION% that record.', ['%ACTION%' => $actionPermissions[$action]]));

            return $this->redirectToRoute('overview', ['contenttypeslug' => $contenttypeslug]);
        }

        if ($this->app['blimp_client.storage']->publish($content)) {
            $this->flashes()->info(Trans::__("Content '%title%' has been changed to '%newStatus%'", ['%title%' => $title, '%newStatus%' => $newStatus]));
        } else {
            $this->flashes()->info(Trans::__("Content '%title%' could not be modified.", ['%title%' => $title]));
        }

        return $this->redirectToRoute('overview', ['contenttypeslug' => $contenttypeslug]);
    }

    /**
     * @return \Bolt\Storage\RecordModifier
     */
    protected function recordModifier() {
        return $this->app['blimp_client.storage.record_modifier'];
    }
}
