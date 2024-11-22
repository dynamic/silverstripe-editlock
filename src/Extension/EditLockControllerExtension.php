<?php

namespace Sheadawson\Editlock\Extension;

use Psr\Container\NotFoundExceptionInterface;
use Sheadawson\Editlock\Model\RecordBeingEdited;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Extension;
use SilverStripe\ORM\ValidationException;
use SilverStripe\Security\Security;

/**
 * EditLockControllerExtension
 *
 * @package silverstripe-editlock
 * @author shea@silverstripe.com.au
 **/
class EditLockControllerExtension extends Extension
{
    /**
     * @var array|string[]
     */
    private static array $allowed_actions = [
        'lock',
    ];

    /**
     * @var array
     */
    private static $lockedClasses = [];


    /**
     * Updates the edit form based on whether it is being edited or not
     *
     * @param $form
     * @param $record
     * @return HTTPResponse|void
     * @throws NotFoundExceptionInterface
     */
    public function updateForm($form, $record)
    {
        if (!$record) {
            return;
        }
        // if the current user can't edit the record anyway, we don't need to do anything
        if ($record && !$record->canEdit()) {
            return $form;
        }

        // check if all classes should be locked by default or a certain list
        $lockedClasses = Config::inst()->get(EditLockControllerExtension::class, 'lockedClasses');
        if (!empty($lockedClasses)) {
            if (!in_array($record->ClassName, $lockedClasses)) {
                return $form;
            }
        }

        // check if this record is being edited by another user
        $beingEdited = RecordBeingEdited::get()->filter([
            'RecordID' => $record->ID,
            'RecordClass' => $record->ClassName,
            'EditorID:not' => Security::getCurrentUser()->ID,
        ])->first();

        if ($beingEdited) {
            if ($this->owner->getRequest()->getVar('editanyway') == '1') {
                $beingEdited->isEditingAnyway();
                return Controller::curr()->redirectBack();
            }
            // if the RecordBeingEdited record has not been updated in the last 15 seconds (via ping)
            // the person editing it must have left the edit form, so delete the RecordBeingEdited
            if (strtotime($beingEdited->LastEdited) < (time() - 15)) {
                $beingEdited->delete();
                // otherwise, there must be someone currently editing this record, so make the form readonly
                // unless they have permission to, and have chosen to edit anyway
            } else {
                if (!$beingEdited->isEditingAnyway()) {
                    $readonlyFields = $form->Fields()->makeReadonly();
                    $form->setFields($readonlyFields);
                    $form->addExtraClass('edit-locked');
                    $form->setAttribute('data-lockedmessage', $beingEdited->getLockedMessage());
                    return;
                }
            }
        }

        $form->setAttribute('data-recordclass', $record->ClassName);
        $form->setAttribute('data-recordid', $record->ID);
        $form->setAttribute('data-lockurl', $this->owner->link('lock'));
        return $form;
    }


    /**
     * Extension hook for LeftAndMain subclasses
     *
     * @param $form
     * @return void
     * @throws NotFoundExceptionInterface
     */
    public function updateEditForm($form): void
    {
        if ($record = $form->getRecord()) {
            $form = $this->updateForm($form, $record);
        }
    }


    /**
     * Extension hook for GridFieldDetailForm_ItemRequest
     *
     * @param $form
     * @return void
     * @throws NotFoundExceptionInterface
     */
    public function updateItemEditForm($form): void
    {
        if ($record = $form->getRecord()) {
            $form = $this->updateForm($form, $record);
        }
    }


    /**
     * Handles ajax pings to create a RecordBeingEdited lock or update an existing one
     *
     * @param $request
     * @return void
     * @throws ValidationException
     */
    public function lock($request): void
    {
        $id = (int)$request->postVar('RecordID');
        $class = $request->postVar('RecordClass');

        $existing = RecordBeingEdited::get()->filter([
            'RecordID' => $id,
            'RecordClass' => $class,
            'EditorID' => Security::getCurrentUser()->ID,
        ])->first();

        if ($existing) {
            $existing->write(false, false, true);
        } else {
            $lock = RecordBeingEdited::create([
                'RecordID' => $id,
                'RecordClass' => $class,
                'EditorID' => Security::getCurrentUser()->ID,
            ]);
            $lock->write();
        }
    }
}
