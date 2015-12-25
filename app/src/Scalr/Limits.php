<?php

/**
 * Class Scalr_Limits
 *
 * @deprecated
 * @see Scalr\Model\Entity\Limit
 */
class Scalr_Limits extends Scalr_Model
{
    const ACCOUNT_FARMS = 'account.farms';
    const ACCOUNT_ENVIRONMENTS = 'account.environments';
    const ACCOUNT_USERS = 'account.users';
    const ACCOUNT_SERVERS = 'account.servers';

    const TYPE_SOFT = 'soft';
    const TYPE_HARD = 'hard';

    protected $dbTableName = 'account_limits';
    protected $dbPrimaryKey = "id";
    protected $dbMessageKeyNotFound = "Limit #%s not found in database";

    protected $dbPropertyMap = array(
        'id'			=> 'id',
        'account_id'	=> 'accountId',
        'limit_name'	=> 'limitName',
        'limit_value'	=> 'limitValue',
        'limit_type'	=> 'limitType',
        'limit_type_value' => 'limitTypeValue'
    );

    protected
        $accountId,
        $limitName,
        $limitValue = null,
        $limitType,
        $limitTypeValue;

    private $isBillingEnabled;

    public function __construct($id = null)
    {
        parent::__construct($id);
        $this->isBillingEnabled = \Scalr::config('scalr.billing.enabled');
    }

    /**
     * @return Scalr_Limits
     */
    public function Load($limitName, $accountId) {

        $this->accountId = $accountId;
        $this->limitName = $limitName;

        $info = $this->db->GetRow("SELECT * FROM account_limits WHERE account_id=? AND limit_name=? LIMIT 1", array($accountId, $limitName));
        if ($info)
            return $this->loadBy($info);
        else
        {
            $this->limitType = self::TYPE_HARD;
            $this->limitTypeValue = 1;

            if ($this->limitName == self::ACCOUNT_SERVERS) {
                $this->limitType = self::TYPE_SOFT;
                $this->limitTypeValue = 20;
            }

            return $this;
        }
    }

    /**
     * @return boolean
     */
    public function check($value) {

        if (!$this->isBillingEnabled)
            return true;

        if (is_null($this->limitValue) || $this->limitValue == -1)
            return true;

        switch ($this->limitType) {
            case self::TYPE_HARD:
                return ($this->getCurrentUsage()+$value <= $this->limitValue);
                break;

            case self::TYPE_SOFT:

                $limitValue = $this->limitValue + ($this->limitValue / 100 * $this->limitTypeValue);
                return ($this->getCurrentUsage()+$value <= $limitValue);

                break;
        }
    }

    public function setLimitValue($limitValue) {
        $this->limitValue = $limitValue;
        $this->save();
    }

    /**
     * @return integer
     */
    public function getLimitValue() {
        return !is_null($this->limitValue) ? $this->limitValue : -1;
    }

    /**
     * @return integer
     */
    public function getCurrentUsage() {
        switch($this->limitName) {
            case self::ACCOUNT_FARMS:
                return (int)$this->db->GetOne("SELECT COUNT(*) FROM farms WHERE clientid=?", array($this->accountId));
                break;
            case self::ACCOUNT_ENVIRONMENTS:
                return (int)$this->db->GetOne("SELECT COUNT(*) FROM client_environments WHERE client_id=?", array($this->accountId));
                break;
            case self::ACCOUNT_SERVERS:
                return (int)$this->db->GetOne("SELECT COUNT(*) FROM servers WHERE client_id=? AND status IN (?,?,?)", array(
                    $this->accountId,
                    SERVER_STATUS::PENDING,
                    SERVER_STATUS::INIT,
                    SERVER_STATUS::RUNNING
                ));
                break;
            case self::ACCOUNT_USERS:
                return (int)$this->db->GetOne("SELECT COUNT(*) FROM account_users WHERE account_id=?", array($this->accountId));
                break;
        }

        return 0;
    }

    /**
     *
     * @return Scalr_Limits
     */
    public static function init($className = null) {
        return parent::init();
    }
}
