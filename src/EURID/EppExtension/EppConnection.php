<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\EURID\EppExtension;

use Metaregistrar\EPP\eppCreateContactResponse;
use Metaregistrar\EPP\eppInfoContactRequest;
use Metaregistrar\EPP\eppPollRequest;
use Metaregistrar\EPP\eppConnection as BaseEppConnection;
use Psr\Log\LoggerInterface;
use Upmind\ProvisionProviders\DomainNames\EURID\EppExtension\Requests\EppUpdateAuthInfoRequest;
use Metaregistrar\EPP\eppInfoDomainResponse;
use Metaregistrar\EPP\euridEppInfoDomainResponse;
use Metaregistrar\EPP\eppInfoDomainRequest;

/**
 * Class EppConnection
 * @package Upmind\ProvisionProviders\DomainNames\EURID\EppExtension
 */
class EppConnection extends BaseEppConnection
{
    /**
     * @var LoggerInterface
     */
    protected LoggerInterface $logger;

    protected $objuri = array('urn:ietf:params:xml:ns:domain-1.0' => 'domain', 'urn:ietf:params:xml:ns:contact-1.0' => 'contact');

    /**
     * EppConnection constructor.
     * @param bool $logging
     * @param string|null $settingsFile
     */
    public function __construct(bool $logging = false, string $settingsFile = null)
    {
        // Call parent's constructor
        parent::__construct($logging, $settingsFile);

        parent::setServices($this->objuri);

        parent::useExtension('authInfo-1.1' );
        parent::useExtension('poll-1.2');
        parent::useExtension('contact-ext-1.3');
        parent::useExtension('domain-ext-2.3');

        parent::addCommandResponse(eppInfoDomainRequest::class, euridEppInfoDomainResponse::class);
    }

    /**
     * Set a PSR-3 logger.
     */
    public function setPsrLogger(?LoggerInterface $logger): void
    {
        $this->logger = $logger;
        if (isset($logger)) {
            $this->logFile = '/dev/null';
        }
    }

    /**
     * Writes a log message to the log file or PSR-3 logger.
     *
     * @inheritdoc
     */
    public function writeLog($text, $action)
    {
        if ($this->logging && isset($this->logger)) {
            $message = $text;
            $message = $this->hideTextBetween($message, '<clID>', '</clID>');
            // Hide password in the logging
            $message = $this->hideTextBetween($message, '<pw>', '</pw>');
            $message = $this->hideTextBetween($message, '<pw><![CDATA[', ']]></pw>');
            // Hide new password in the logging
            $message = $this->hideTextBetween($message, '<newPW>', '</newPW>');
            $message = $this->hideTextBetween($message, '<newPW><![CDATA[', ']]></newPW>');
            // Hide domain password in the logging
            $message = $this->hideTextBetween($message, '<domain:pw>', '</domain:pw>');
            $message = $this->hideTextBetween($message, '<domain:pw><![CDATA[', ']]></domain:pw>');
            // Hide contact password in the logging
            $message = $this->hideTextBetween($message, '<contact:pw>', '</contact:pw>');
            $message = $this->hideTextBetween($message, '<contact:pw><![CDATA[', ']]></contact:pw>');

            $this->logger->debug(sprintf("EURid [%s]:\n %s", $action, trim($message)));
        }

        parent::writeLog($text, $action);
    }
}
