<?php
namespace Scalr\Service\CloudStack\Services\SshKeyPair\DataType;

use Scalr\Service\CloudStack\DataType\AbstractDataType;

/**
 * UpdateSshKeyData
 *
 * @author   Vlad Dobrovolskiy  <v.dobrovolskiy@scalr.com>
 * @since    4.5.2
 */
class UpdateSshKeyData extends AbstractDataType
{

    /**
     * Required
     * Name of the keypair
     *
     * @var string
     */
    public $name;

    /**
     * An optional account for the ssh key. Must be used with domainId.
     *
     * @var string
     */
    public $account;

    /**
     * An optional domainId for the ssh key.
     * If the account parameter is used, domainId must also be used.
     *
     * @var string
     */
    public $domainid;

    /**
     * An optional project for the ssh key
     *
     * @var string
     */
    public $projectid;

    /**
     * Constructor
     *
     * @param   string  $name         Name of the keypair
     */
    public function __construct($name)
    {
        $this->name = $name;
    }

}
