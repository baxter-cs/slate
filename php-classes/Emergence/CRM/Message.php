<?php

namespace Emergence\CRM;

use Emergence\People\ContactPoints\Email;

class Message extends \VersionedRecord
{
    public static $subjectFormat = '%s';
    public static $forwardPrefixFormat = "Forwarded by <a href=\"mailto:%s\">%s</a>:<hr/>\n";

    // VersionedRecord configuration
    public static $historyTable = 'history_messages';

    // ActiveRecord configuration
    public static $tableName = 'messages';
    public static $singularNoun = 'message';
    public static $pluralNoun = 'messages';

    // required for shared-table subclassing support
    public static $rootClass = __CLASS__;
    public static $defaultClass = __CLASS__;
    public static $subClasses = [__CLASS__];

    public static $fields = [
        'ContextClass' => [
            'type' => 'enum'
            ,'values' => [
                \Emergence\People\Person::class
            ]
        ]
        ,'ContextID' => 'uint'
        ,'AuthorID' => [
            'type' => 'integer'
            ,'unsigned' => true
        ]
        ,'Subject'
        ,'Message' => 'clob'
        ,'MessageFormat' => [
            'type' => 'enum'
            ,'values' => ['plain','html']
        ]

        ,'Status' => [
            'type' => 'enum'
            ,'values' => ['Private Draft','Shared Draft','Sent','Deleted']
            ,'default' => 'Private Draft'
        ]
        ,'ParentMessageID' => [
            'type' => 'integer'
            ,'unsigned' => true
            ,'notnull' => false
        ]
        ,'Source' => [
            'type' => 'enum'
            ,'values' => ['System','Direct','Email','Import']
            ,'default' => 'Direct'
        ]

        ,'Sent' => [
            'type' => 'timestamp'
            ,'notnull' => false
        ]
    ];


    public static $relationships = [
        'Context' => [
            'type' => 'context-parent'
        ]
        ,'Author' => [
            'type' => 'one-one'
            ,'class' => \Emergence\People\Person::class
        ]
        ,'ParentMessage' => [
            'type' => 'one-one'
            ,'class' => __CLASS__
        ]
        ,'Recipients' => [
            'type' => 'one-many'
            ,'class' => \Emergence\CRM\MessageRecipient::class
            ,'foreign' => 'MessageID'
        ]
    ];

    public static $dynamicFields = [
        'Context',
        'Author',
        'ParentMessage',
        'Recipients'
    ];

    public static $validators = [
        'Subject' => [

            'required' => true,
            'errorMessage' => 'A subject is required'
        ],
        'Message' => [
            'required' => true,
            'validator' => 'string_multiline',
            'errorMessage' => 'A message is required'
        ]
    ];

    public function addRecipient(\Emergence\People\Person $Person, \Emergence\People\ContactPoint\Email $EmailContactPoint)
    {
        try {
            $MsgRecipient = MessageRecipient::create([
                'MessageID' => $this->ID
                ,'PersonID' => $Person->ID
                ,'EmailContactID' => $EmailContactPoint->ID
            ], true);
        } catch (DuplicateKeyException $e) {
            $MsgRecipient = MessageRecipient::getByWhere([
                'MessageID' => $this->ID
                ,'PersonID' => $Person->ID
            ]);
        }

        return $MsgRecipient;
    }

    public function save($deep = true)
    {
        if (!$this->Sent && $this->Status == 'Sent') {
            $this->Sent = time();
        }

        if (!$this->Author) {
            $this->Author = $GLOBALS['Session']->Person;
        }

        parent::save($deep);
    }

    public function send()
    {
        $newEmailRecipients = [];

        foreach ($this->Recipients AS $Recipient) {
            if ($Recipient->Status == 'Pending') {
                $Recipient->Status = 'Sent';
                $newEmailRecipients[] = $Recipient;
            }
        }

        if (!count($newEmailRecipients)) {
            return 0;
        }
        error_reporting(E_ALL);
        $success = \Emergence\Mailer\Mailer::send(
            $this->getEmailRecipientsList($newEmailRecipients)
            ,$this->getEmailSubject()
            ,$this->getEmailBody()
            ,$this->getEmailFrom()
            ,$this->getEmailHeaders()
        );

        if ($success) {
            $this->Status = 'Sent';
            $this->save();

            return count($newEmailRecipients);
        } else {
            print_r(error_get_last());
            throw new \Exception('Failed to inject message into email system');
        }
    }


    public function getEmailRecipientsList($recipients = false)
    {
        if (!is_array($recipients)) {
            $recipients = $this->Recipients;
        }

        return array_map(function($Recipient) {
            if (!$Recipient->EmailContactID || $Recipient->Person->PrimaryEmailID == $Recipient->EmailContactID) {
                return $Recipient->Person->EmailRecipient;
            } else {
                $Email = \Emergence\People\ContactPoint\Email::getByID($Recipient->EmailContactID);

                return $Email->toRecipientString();
            }
        }, $recipients);
    }

    public function getEmailSubject()
    {
        return sprintf(static::$subjectFormat, $this->Subject);
    }

    public function getEmailBody()
    {
        $Sender = $GLOBALS['Session']->Person;

        if ($Sender->ID != $this->AuthorID) {
            return sprintf(static::$forwardPrefixFormat, $Sender->Email, $Sender->FullName).$this->Message;
        } else {
            return $this->Message;
        }
    }

    public function getEmailFrom()
    {
        return $this->Author->EmailRecipient;
    }

    public function getEmailHeaders()
    {
        $replyTo = $this->getEmailRecipientsList();

        if (!in_array($this->Author->EmailRecipient, $replyTo)) {
            $replyTo[] = $this->Author->EmailRecipient;
        }

        return [
            'Reply-To: '.implode(', ', $replyTo)
            ,'X-MessageID: '.$this->ID
        ];
    }
}