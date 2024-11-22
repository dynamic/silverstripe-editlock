<?php

namespace Sheadawson\Editlock\Model;

use Psr\Container\NotFoundExceptionInterface;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SilverStripe\Security\PermissionProvider;
use SilverStripe\Security\Security;

/**
 * RecordBeingEdited
 *
 * @package silverstripe-editlock
 * @author shea@silverstripe.com.au
 **/
class RecordBeingEdited extends DataObject implements PermissionProvider
{
    /**
     * @var string
     */
    private static string $table_name = 'RecordBeingEdited';

    /**
     * @var string
     */
    private static string $singular_name = "Record Being Edited";

    /**
     * @var string
     */
    private static string $plural_name = "Records Being Edited";

    /**
     * @var array|string[]
     */
    private static array $db = [
        'RecordClass' => 'Varchar',
        'RecordID' => 'Int',
    ];

    /**
     * @var \class-string[]
     */
    private static array $has_one = [
        'Editor' => Member::class,
    ];


    /**
     * Generates the edit lock warning message displayed to the user
     *
     * @return string
     **/
    public function getLockedMessage(): string
    {
        $editor = $this->Editor();
        $editorString = $editor->getTitle();
        if ($editor->Email) {
            $editorString .= " &lt;<a href='mailto:$editor->Email'>$editor->Email</a>&gt;";
        }
        $message = sprintf(
            _t(__CLASS__ . '.LOCKEDMESSAGE', 'Sorry, this record is currently being edited by %s. To avoid conflicts and data loss, editing will be locked until they are finished.'),
            $editorString
        );

        if ($this->canEditAnyway()) {
            $editAnywayLink = Controller::join_links(Controller::curr()->getRequest()->requestVar('url'), '?editanyway=1');
            $message .= "<span style='float:right'>";
            $message .= sprintf(_t('RecordBeingEdited.EDITANYWAY', 'I understand the risks, %s edit anyway %s'), "<a href='$editAnywayLink'>", "</a>");
            $message .= "</span>";
        }

        return $message;
    }


    /**
     * Checks to see if the current user can and edit the record anyway
     *
     * @return bool
     **/
    public function canEditAnyway(): bool
    {
        return (bool)Permission::check('RECORDBEINGEDITED_EDITANYWAY');
    }


    /**
     * Checks to see if the current user can and has elected to edit the record anyway
     *
     * @return bool
     * @throws NotFoundExceptionInterface
     */
    public function isEditingAnyway(): bool
    {
        if (!$this->canEditAnyway()) {
            return false;
        }

        $request = Injector::inst()->get(HTTPRequest::class);
        $session = $request->getSession();

        $sessionVar = 'EditAnyway_' . Security::getCurrentUser()->ID . '_' . $this->ID;
        if (Controller::curr()->getRequest()->getVar('editanyway') == '1') {
            $session->set($sessionVar, true);

            return true;
        }
        return (bool)$session->get($sessionVar);
    }


    /**
     * @return array
     **/
    public function providePermissions(): array
    {
        return [
            'RECORDBEINGEDITED_EDITANYWAY' => [
                'name' => _t('RecordBeingEdited.PERMISSION_EDITANYWAY_DESCRIPTION', 'Edit a record that another user is editing'),
                'help' => _t('RecordBeingEdited.PERMISSION_EDITANYWAY_HELP', 'Let\'s the user dismiss the edit lock and warning'),
                'category' => _t('RecordBeingEdited.PERMISSION_EDITANYWAY_CATEGORY', 'Content permissions'),
                'sort' => 100,
            ],
        ];
    }
}
