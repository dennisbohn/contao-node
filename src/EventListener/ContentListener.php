<?php

declare(strict_types=1);

namespace Terminal42\NodeBundle\EventListener;

use Contao\CoreBundle\Exception\AccessDeniedException;
use Contao\DataContainer;
use Contao\Input;
use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use Terminal42\NodeBundle\Model\NodeModel;
use Terminal42\NodeBundle\PermissionChecker;

class ContentListener
{
    /**
     * ContentListener constructor.
     */
    public function __construct(
        private Connection $db,
        private PermissionChecker $permissionChecker,
    ) {
    }

    /**
     * On data container load callback.
     */
    public function onLoadCallback(DataContainer $dc): void
    {
        switch (Input::get('act')) {
            case 'edit':
            case 'delete':
            case 'show':
            case 'copy':
            case 'copyAll':
            case 'cut':
            case 'cutAll':
                $nodeId = $this->findNodeIdByContentId($dc->id);
                break;

            case 'paste':
                if ('create' === Input::get('mode')) {
                    $nodeId = $dc->id;
                } else {
                    $nodeId = $this->findNodeIdByContentId($dc->id);
                }
                break;

            case 'create':
                // Nested element
                if (Input::get('ptable') === 'tl_content') {
                    $nodeId = $this->findNodeIdByContentId(Input::get('pid'));
                } else {
                    $nodeId = Input::get('pid');
                }
                break;

            default:
                // Ajax requests such as toggle
                if (Input::get('field') && ($id = Input::get('cid') ?: Input::get('id'))) {
                    $nodeId = $this->findNodeIdByContentId($id);
                // Nested element
                } else if (Input::get('ptable') === 'tl_content') {
                    $nodeId = $this->findNodeIdByContentId($dc->id);
                } else {
                    $nodeId = $dc->id;
                }
                break;
        }

        $type = $this->db->fetchOne('SELECT type FROM tl_node WHERE id=?', [$nodeId]);

        // Throw an exception if the node is not present or is of a folder type
        if (!$type || NodeModel::TYPE_FOLDER === $type) {
            throw new AccessDeniedException('Node of folder type cannot have content elements');
        }

        $this->checkPermissions((int) $nodeId);
    }

    /**
     * On nodes fields save callback.
     *
     * @throws \InvalidArgumentException
     */
    public function onNodesSaveCallback(string|null $value, DataContainer $dc): string|null
    {
        $ids = (array) StringUtil::deserialize($value, true);
        $ids = array_map('intval', $ids);

        if (\count($ids) > 0) {
            $folders = $this->db->fetchAllAssociative('SELECT name FROM tl_node WHERE id IN ('.implode(', ', $ids).') AND type=?', [NodeModel::TYPE_FOLDER]);

            // Do not allow folder nodes
            if (\count($folders) > 0) {
                throw new \InvalidArgumentException(sprintf($GLOBALS['TL_LANG']['ERR']['invalidNodes'], implode(', ', array_column($folders, 'name'))));
            }

            $ids = array_map('intval', $ids);

            // Check for potential circular reference
            if ('tl_node' === $dc->activeRecord->ptable && \in_array((int) $dc->activeRecord->pid, $ids, true)) {
                throw new \InvalidArgumentException($GLOBALS['TL_LANG']['ERR']['circularReference']);
            }
        }

        return $value;
    }

    /**
     * Check the permissions.
     */
    private function checkPermissions(int $nodeId): void
    {
        if (!$this->permissionChecker->hasUserPermission(PermissionChecker::PERMISSION_CONTENT)) {
            throw new AccessDeniedException('The user is not allowed to manage the node content');
        }

        if (!$this->permissionChecker->isUserAllowedNode($nodeId)) {
            throw new AccessDeniedException(sprintf('The user is not allowed to manage the content of node ID %s', $nodeId));
        }
    }

    /**
     * Find node id by content id.
     */
    private function findNodeIdByContentId($contentId): int
    {
        $pid = $contentId;
        $ptable = 'tl_content';

        // Recursive node id finder
        while ($ptable === 'tl_content') {
            list($pid, $ptable) = $this->db->fetchNumeric('SELECT pid, ptable FROM tl_content WHERE id=?', [$pid]);
        }

        return $pid;
    }
}
