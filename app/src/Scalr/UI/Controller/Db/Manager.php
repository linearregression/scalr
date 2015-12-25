<?php

use Scalr\Acl\Acl;
use Scalr\Modules\PlatformFactory;
use Scalr\Model\Entity;

class Scalr_UI_Controller_Db_Manager extends Scalr_UI_Controller
{

    public function hasAccess()
    {
        return parent::hasAccess() && $this->request->isAllowed(Acl::RESOURCE_DB_DATABASE_STATUS);
    }

    private function getDbAccessDetails(DBFarmRole $dbFarmRole)
    {
        $retval = array(
            'username' => 'scalr',
            'password' => '',
            'dns' => false
        );

        if ($dbFarmRole->GetRoleObject()->hasBehavior(ROLE_BEHAVIORS::POSTGRESQL)) {
            $retval['password'] = $dbFarmRole->GetSetting(Scalr_Db_Msr_Postgresql::ROOT_PASSWORD);
            $behavior = ROLE_BEHAVIORS::POSTGRESQL;
        } elseif ($dbFarmRole->GetRoleObject()->hasBehavior(ROLE_BEHAVIORS::REDIS)) {

            if ($dbFarmRole->GetSetting(Scalr_Db_Msr_Redis::USE_PASSWORD) == 1) {
                $ports = @json_decode($dbFarmRole->GetSetting(Scalr_Db_Msr_Redis::PORTS_ARRAY));
                $passwords = @json_decode($dbFarmRole->GetSetting(Scalr_Db_Msr_Redis::PASSWD_ARRAY));

                if (!$ports && !$passwords)
                    $retval['password'] = $dbFarmRole->GetSetting(Scalr_Db_Msr_Redis::MASTER_PASSWORD);
                else {
                    foreach ($ports as $i=>$port) {
                        $retval['password'] .= "Port {$port}: {$passwords[$i]}<br>";
                    }
                }
            } else {
                $retval['password'] = '<span style="color:red;">Password authentication is disabled</span>';
            }

            $behavior = ROLE_BEHAVIORS::REDIS;
        } elseif ($dbFarmRole->GetRoleObject()->hasBehavior(ROLE_BEHAVIORS::MYSQL2)) {
            $retval['password'] = $dbFarmRole->GetSetting(Scalr_Db_Msr_Mysql2::ROOT_PASSWORD);
            $behavior = ROLE_BEHAVIORS::MYSQL2;
        } elseif ($dbFarmRole->GetRoleObject()->hasBehavior(ROLE_BEHAVIORS::PERCONA)) {
            $retval['password'] = $dbFarmRole->GetSetting(Scalr_Db_Msr_Percona::ROOT_PASSWORD);
            $behavior = ROLE_BEHAVIORS::PERCONA;
        } elseif ($dbFarmRole->GetRoleObject()->hasBehavior(ROLE_BEHAVIORS::MARIADB)) {
            $retval['password'] = $dbFarmRole->GetSetting(Scalr_Db_Msr_Mariadb::ROOT_PASSWORD);
            $behavior = ROLE_BEHAVIORS::MARIADB;
        }

        if (\Scalr::config('scalr.dns.static.enabled')) {
            $retval['dns'] = array(
                'master' => array(
                    'private' 	=> "int.master.{$behavior}.{$dbFarmRole->GetFarmObject()->Hash}." . \Scalr::config('scalr.dns.static.domain_name'),
                    'public'	=> "ext.master.{$behavior}.{$dbFarmRole->GetFarmObject()->Hash}." . \Scalr::config('scalr.dns.static.domain_name')
                ),
                'slave' => array(
                    'private' 	=> "int.slave.{$behavior}.{$dbFarmRole->GetFarmObject()->Hash}." . \Scalr::config('scalr.dns.static.domain_name'),
                    'public'	=> "ext.slave.{$behavior}.{$dbFarmRole->GetFarmObject()->Hash}." . \Scalr::config('scalr.dns.static.domain_name')
                )
            );
        }

        return $retval;
    }

    private function getPmaDetails(DBFarmRole $dbFarmRole)
    {
        $retval = array(
            'configured' => false
        );

        if ($dbFarmRole->GetSetting(Entity\FarmRoleSetting::MYSQL_PMA_USER)) {
            $retval['configured'] = true;
        } else {
            $errmsg = $dbFarmRole->GetSetting(Entity\FarmRoleSetting::MYSQL_PMA_REQUEST_ERROR);
            if (!$errmsg) {
                $time = $dbFarmRole->GetSetting(Entity\FarmRoleSetting::MYSQL_PMA_REQUEST_TIME);
                if ($time) {
                    if ($time + 3600 < time()) {
                        $retval['accessError'] = _("Scalr didn't receive auth info from MySQL instance. Please check that MySQL running and Scalr has access to it.");
                    } else {
                        $retval['accessSetupInProgress'] = true;
                    }
                }
            } else {
                $retval['accessError'] = $errmsg;
            }
        }

        return $retval;
    }

    private function getDbStorageStatus(DBServer $masterServer, $behavior)
    {
        // Get Stoarge usage
        $size = array(
            'total' => -1,
            'used' => -1,
            'free' => -1
        );

        try {
            if ($behavior == ROLE_BEHAVIORS::REDIS)
                $mpoint = '/mnt/redisstorage';
            elseif ($behavior == ROLE_BEHAVIORS::POSTGRESQL)
                $mpoint = '/mnt/pgstorage';
            else
                $mpoint = '/mnt/dbstorage';


            $usage = (array)$masterServer->scalarizr->system->statvfs(array($mpoint));
            $size = (array)$usage[$mpoint];

            if ($size['total']) {
                $size['used'] = $size['total'] - $size['free'];

                // Convert KB to GB
                foreach ($size as $k=>$v) {
                    $size[$k] = round($v / 1024 / 1024, 2);
                }
            }

        } catch (Exception $e) {
            $this->response->debugException($e);
        }

        $retval = array(
            'engine' => $masterServer->GetFarmRoleObject()->GetSetting(Scalr_Db_Msr::DATA_STORAGE_ENGINE),
            'level'  => $masterServer->GetFarmRoleObject()->GetSetting(Scalr_Db_Msr::DATA_STORAGE_RAID_LEVEL),
            'id'     => $masterServer->GetFarmRoleObject()->GetSetting(Scalr_Db_Msr::VOLUME_ID) ? $masterServer->GetFarmRoleObject()->GetSetting(Scalr_Db_Msr::VOLUME_ID) : '',
            'fs'     => $masterServer->GetFarmRoleObject()->GetSetting(Scalr_Db_Msr::DATA_STORAGE_FSTYPE),
            'size'   => $size
        );

        if ($this->request->getHeaderVar('Interface-Beta')) {
            $retval['growSupported'] = in_array($retval['engine'], array(MYSQL_STORAGE_ENGINE::EBS, MYSQL_STORAGE_ENGINE::RAID_EBS)) &&
                in_array($behavior, array(ROLE_BEHAVIORS::MYSQL2, ROLE_BEHAVIORS::PERCONA, ROLE_BEHAVIORS::MARIADB, ROLE_BEHAVIORS::REDIS, ROLE_BEHAVIORS::POSTGRESQL));
        } else {
            $retval['growSupported'] = in_array($retval['engine'], array(MYSQL_STORAGE_ENGINE::EBS, MYSQL_STORAGE_ENGINE::RAID_EBS)) &&
            in_array($behavior, array(ROLE_BEHAVIORS::MYSQL2, ROLE_BEHAVIORS::PERCONA, ROLE_BEHAVIORS::MARIADB));
        }

        if ($retval['growSupported'] && $retval['engine'] == MYSQL_STORAGE_ENGINE::RAID_EBS && in_array($retval['level'], array('0', '10'))) {
            $retval['growSupported'] = false;
        }

        if ($retval['growSupported']) {
            if ($retval['engine'] == MYSQL_STORAGE_ENGINE::EBS) {
                $retval['ebs_settings'] = array(
                    'volumeType' => $masterServer->GetFarmRoleObject()->GetSetting(Scalr_Db_Msr::DATA_STORAGE_EBS_TYPE),
                    'size' => $masterServer->GetFarmRoleObject()->GetSetting(Scalr_Db_Msr::DATA_STORAGE_EBS_SIZE)
                );
                if ($retval['ebs_settings']['volumeType'] == 'io1') {
                    $retval['ebs_settings']['iops'] = $masterServer->GetFarmRoleObject()->GetSetting(Scalr_Db_Msr::DATA_STORAGE_EBS_IOPS);
                }
            } else {
                $retval['ebs_settings'] = array(
                    'volumeType' => $masterServer->GetFarmRoleObject()->GetSetting(Scalr_Db_Msr::DATA_STORAGE_RAID_EBS_DISK_TYPE),
                    'size' => $masterServer->GetFarmRoleObject()->GetSetting(Scalr_Db_Msr::DATA_STORAGE_RAID_DISK_SIZE)
                );
                if ($retval['ebs_settings']['volumeType'] == 'io1') {
                    $retval['ebs_settings']['iops'] = $masterServer->GetFarmRoleObject()->GetSetting(Scalr_Db_Msr::DATA_STORAGE_RAID_EBS_DISK_IOPS);
                }
            }
        }

        $retval['growLastError'] = $masterServer->GetFarmRoleObject()->GetSetting(Entity\FarmRoleSetting::STORAGE_GROW_LAST_ERROR);
        if ($retval['growLastError']) {
            $masterServer->GetFarmRoleObject()->SetSetting(Entity\FarmRoleSetting::STORAGE_GROW_LAST_ERROR, null);
        }

        if (($operationId = $masterServer->GetFarmRoleObject()->GetSetting(Entity\FarmRoleSetting::STORAGE_GROW_OPERATION_ID)) &&
            ($serverId = $masterServer->GetFarmRoleObject()->GetSetting(Entity\FarmRoleSetting::STORAGE_GROW_SERVER_ID)) &&
            ($serverId == $masterServer->serverId)
        ) {
            $retval['growOperation'] = [
                'operationId' => $operationId,
                'serverId' => $serverId
            ];
        }

        switch ($retval['engine']) {
            case MYSQL_STORAGE_ENGINE::EBS:
                $retval['engineName'] = 'Single EBS volume';
                break;
            case MYSQL_STORAGE_ENGINE::RAID_EBS:
                $retval['engineName'] = sprintf('RAID %s on %s EBS volumes',
                    $masterServer->GetFarmRoleObject()->GetSetting(Scalr_Db_Msr::DATA_STORAGE_RAID_LEVEL),
                    $masterServer->GetFarmRoleObject()->GetSetting(Scalr_Db_Msr::DATA_STORAGE_RAID_DISKS_COUNT)
                );
                break;
            case MYSQL_STORAGE_ENGINE::LVM:
                $retval['engineName'] = 'LVM on ephemeral device(s)';
                break;
            case MYSQL_STORAGE_ENGINE::EPH:
                $retval['engineName'] = 'Ephemeral device';
                break;
            case MYSQL_STORAGE_ENGINE::CSVOL:
                $retval['engineName'] = 'Single Cloudstack volume';
                break;
            default:
                $retval['engineName'] = $retval['engine'];
                break;
        }

        return $retval;
    }

    /**
     * @param   string  $serverId
     * @throws  Scalr_Exception_Core
     */
    public function xGetDbStorageStatusAction($serverId)
    {
        $server = DBServer::LoadByID($serverId);
        $this->user->getPermissions()->validate($server);

        $dbType = $server->GetFarmRoleObject()->GetRoleObject()->getDbMsrBehavior();
        if (empty($dbType)) {
            throw new Scalr_Exception_Core("Unknown db type");
        }
        $dbStorageStatusData = $this->getDbStorageStatus($server, $dbType);

        $this->response->data([
            'storage' => $dbStorageStatusData
        ]);
    }

    public function dashboardAction()
    {
        $this->request->defineParams(array(
            'farmId'     => array('type' => 'int'),
            'farmRoleId' => array('type' => 'int'),
            'type'
        ));

        $dbFarm = DBFarm::LoadByID($this->getParam('farmId'));
        $this->user->getPermissions()->validate($dbFarm);

        if ($this->getParam('farmRoleId')) {
            $dbFarmRole = DBFarmRole::LoadByID($this->getParam('farmRoleId'));
            if ($dbFarmRole->FarmID != $dbFarm->ID) {
                throw new Exception("Role not found");
            }
        } elseif ($this->getParam('type')) {
            foreach ($dbFarm->GetFarmRoles() as $sDbFarmRole) {
                if ($sDbFarmRole->GetRoleObject()->hasBehavior($this->getParam('type'))) {
                    $dbFarmRole = $sDbFarmRole;
                    break;
                }
            }

            if (!$dbFarmRole) {
                throw new Exception("Role not found");
            }
        } else {
            throw new Scalr_UI_Exception_NotFound();
        }

        $data = array(
            'farmRoleId' => $dbFarmRole->ID,
            'farmId'	 => $dbFarmRole->FarmID
        );

        $data['dbType'] = $dbFarmRole->GetRoleObject()->getDbMsrBehavior();
        if (!$data['dbType']) {
            $this->response->failure("Unknown db type");
            return;
        }

        switch ($data['dbType']) {
            case ROLE_BEHAVIORS::MYSQL2:
            case ROLE_BEHAVIORS::PERCONA:
            case ROLE_BEHAVIORS::MARIADB:
                $szrApiNamespace = Scalr_Net_Scalarizr_Client::NAMESPACE_MYSQL;
                break;

            case ROLE_BEHAVIORS::REDIS:
                $szrApiNamespace = Scalr_Net_Scalarizr_Client::NAMESPACE_REDIS;
                $data['extras'] = array(
                    array('name' => 'Processes', 'value' => $dbFarmRole->GetSetting(Scalr_Db_Msr_Redis::NUM_PROCESSES)),
                    array('name' => 'Persistence type', 'value' => $dbFarmRole->GetSetting(Scalr_Db_Msr_Redis::PERSISTENCE_TYPE))
                );
                break;

            case ROLE_BEHAVIORS::POSTGRESQL:
                $szrApiNamespace = Scalr_Net_Scalarizr_Client::NAMESPACE_POSTGRESQL;
                break;
        }

        // Get PMA details for MySQL / Percona
        if (in_array($data['dbType'], array(ROLE_BEHAVIORS::MYSQL, ROLE_BEHAVIORS::MYSQL2, ROLE_BEHAVIORS::PERCONA, ROLE_BEHAVIORS::MARIADB)))
            $data['pma'] = $this->getPmaDetails($dbFarmRole);

        $behavior = Scalr_Role_Behavior::loadByName($data['dbType']);
        $masterServer = $behavior->getMasterServer($dbFarmRole);
        if ($masterServer) {
            // Get Storage details
            $data['storage'] = $this->getDbStorageStatus($masterServer, $data['dbType']);
        }

        // Get Access details and DNS endpoints
        $data['accessDetails'] = $this->getDbAccessDetails($dbFarmRole);
        $data['name'] = ROLE_BEHAVIORS::GetName($data['dbType']);

        // Get data bundle info
        $bundlesEnabled = $dbFarmRole->GetSetting(Scalr_Db_Msr::DATA_BUNDLE_ENABLED);
        $lastActionTime = $dbFarmRole->GetSetting(Scalr_Db_Msr::getConstant("DATA_BUNDLE_LAST_TS"));

        $data['bundles'] = array(
            'history' => $this->db->GetAll("SELECT *, UNIX_TIMESTAMP(date) as date FROM services_db_backups_history WHERE `farm_role_id` = ? AND `operation` = ? ORDER BY id ASC", array(
                $dbFarmRole->ID, 'bundle'
            )),
            'inProgress' => array(
                'status' => (int)$dbFarmRole->GetSetting(Scalr_Db_Msr::DATA_BUNDLE_IS_RUNNING),
                'serverId' => $dbFarmRole->GetSetting(Scalr_Db_Msr::DATA_BUNDLE_SERVER_ID)
            ),
            'last' => $lastActionTime ? Scalr_Util_DateTime::convertTz((int)$lastActionTime, 'd M Y \a\\t H:i:s') : 'Never'
        );
        foreach ($data['bundles']['history'] as &$h)
            $h['date'] = Scalr_Util_DateTime::convertTz((int)$h['date'], 'd M Y \a\\t H:i:s');


        if ($bundlesEnabled) {
            $period = $dbFarmRole->GetSetting(Scalr_Db_Msr::DATA_BUNDLE_EVERY);

            if ($lastActionTime) {
                $nextTime = $lastActionTime + ($period * 3600);
            }

            $data['bundles']['next'] = (!$nextTime || $nextTime < time()) ? "Within 30 minutes" : Scalr_Util_DateTime::convertTz((int)$nextTime, 'd M Y \a\\t H:i:s');
            $data['bundles']['schedule'] = "Every {$period} hours";
        } else {
            $data['bundles']['next'] = " - ";
            $data['bundles']['schedule'] = "Auto-snapshotting disabled";
        }

        // Get backups info
        $lastActionTime = $dbFarmRole->GetSetting(Scalr_Db_Msr::getConstant("DATA_BACKUP_LAST_TS"));
        $nextTime = false;

        $backupsEnabled = $dbFarmRole->GetSetting(Scalr_Db_Msr::DATA_BACKUP_ENABLED);
        $data['backups'] = array(
            'history'    => $this->db->GetAll("SELECT *, UNIX_TIMESTAMP(date) as date FROM services_db_backups_history WHERE `farm_role_id` = ? AND `operation` = ? ORDER BY id ASC", array(
                $dbFarmRole->ID, 'backup'
            )),
            'inProgress' => array(
                'status'      => (int)$dbFarmRole->GetSetting(Scalr_Db_Msr::DATA_BACKUP_IS_RUNNING),
                'serverId'    => $dbFarmRole->GetSetting(Scalr_Db_Msr::DATA_BACKUP_SERVER_ID)
            ),
            'last'       => $lastActionTime ? Scalr_Util_DateTime::convertTz((int)$lastActionTime, 'd M Y \a\\t H:i:s') : 'Never',
            'supported'  => !PlatformFactory::isCloudstack($dbFarmRole->Platform) &&
                           (!PlatformFactory::isOpenstack($dbFarmRole->Platform) ||
                             $this->getEnvironment()
                                  ->cloudCredentials($dbFarmRole->Platform)
                                  ->properties[Entity\CloudCredentialsProperty::OPENSTACK_EXT_SWIFT_ENABLED])
        );
        foreach ($data['backups']['history'] as &$h)
            $h['date'] = Scalr_Util_DateTime::convertTz((int)$h['date'], 'd M Y \a\\t H:i:s');


        if ($backupsEnabled) {
            $period = $dbFarmRole->GetSetting(Scalr_Db_Msr::DATA_BACKUP_EVERY);

            if ($lastActionTime) {
                $nextTime = $lastActionTime + ($period * 3600);
            }

            $data['backups']['next'] = (!$nextTime || $nextTime < time()) ? "Within 30 minutes" : Scalr_Util_DateTime::convertTz((int)$nextTime, 'd M Y \a\\t H:i:s');
            $data['backups']['schedule'] = "Every {$period} hours";
        } else {
            $data['backups']['next'] = " - ";
            $data['backups']['schedule'] = "Auto-backups disabled";
        }

        /*
        if ($dbFarmRole->GetSetting(Scalr_Db_Msr::DATA_STORAGE_ENGINE) == 'lvm') {
            $data['noDataBundleForSlaves'] = ($dbFarmRole->GetSetting(Scalr_Role_DbMsrBehavior::ROLE_NO_DATA_BUNDLE_FOR_SLAVES)) ? true : false;
        }
        */

        $conf = $this->getContainer()->config->get('scalr.load_statistics.connections.plotter');

        foreach ($dbFarmRole->GetServersByFilter(array('status' => array(SERVER_STATUS::INIT, SERVER_STATUS::RUNNING, SERVER_STATUS::PENDING))) as $dbServer) {
            $isMaster = ($dbServer->GetProperty(Scalr_Db_Msr::REPLICATION_MASTER) == 1);
            $serverRole = $isMaster ? 'master' : 'slave';

            $serverInfo = array(
                'status'        => $dbServer->status,
                'remoteIp'      => $dbServer->remoteIp,
                'localIp'       => $dbServer->localIp,
                'serverId'      => $dbServer->serverId,
                'cloudServerId' => $dbServer->GetCloudServerID(),
                'cloudLocation' => $dbServer->GetCloudLocation(),
                'serverRole'    => $serverRole,
                'index'         => $dbServer->index,
                'disabledServerPermission' => !$this->request->isFarmAllowed($dbFarm, Acl::PERM_FARMS_SERVERS)
            );

            if ($this->request->isFarmAllowed($dbFarm, Acl::PERM_FARMS_STATISTICS)) {
                $serverInfo['monitoring'] = [
                    'farmId'     => $dbFarmRole->FarmID,
                    'farmRoleId' => $dbFarmRole->ID,
                    'index'      => $dbServer->index,
                    'hash'       => $dbFarm->Hash,
                    'hostUrl'    => "{$conf['scheme']}://{$conf['host']}:{$conf['port']}"
                ];
            }

            if ($dbServer->platform == SERVER_PLATFORMS::EC2)
                $serverInfo['cloudLocation'] = $dbServer->GetProperty(EC2_SERVER_PROPERTIES::AVAIL_ZONE);

            if ($dbServer->status == SERVER_STATUS::RUNNING) {
                try {
                    $rStatus = $dbServer->scalarizr->{$szrApiNamespace}->replicationStatus();

                    if ($data['dbType'] != ROLE_BEHAVIORS::REDIS) {
                        $rStatus = (array)$rStatus->{$serverRole};
                        $replication = $rStatus;
                    } else {
                        if ($isMaster) {
                            $rStatus = (array)$rStatus->masters;
                            foreach ($rStatus as $port => $status) {
                                $rStatus['status'] = $status;

                                if ($status != 'up')
                                    break;
                            }
                        } else {
                            $rStatus = (array)$rStatus->slaves;
                            foreach ($rStatus as $port => $status) {
                                $rStatus['status'] = $status->status;

                                if ($status->status != 'up')
                                    break;
                            }
                        }

                        $replication = $rStatus;
                    }

                    if (in_array($data['dbType'], array(ROLE_BEHAVIORS::MYSQL2, ROLE_BEHAVIORS::PERCONA, ROLE_BEHAVIORS::MARIADB))) {
                        if ($rStatus['status'] == 'up' && $replication['seconds_behind_master'] > 0) {
                            $status = 'lagging';
                        } else {
                            $status = $rStatus['status'];
                        }
                    } elseif ($data['dbType'] == ROLE_BEHAVIORS::REDIS) {
                        $status = $rStatus['status'];
                    } elseif ($data['dbType'] == ROLE_BEHAVIORS::POSTGRESQL) {
                        if ($rStatus['status'] == 'up' && $replication['Xlog_delay'] > 1000) {
                            $status = 'lagging';
                        } else {
                            $status = $rStatus['status'];
                        }
                    }

                    $serverInfo['replication'] = array(
                        'status'        => $status,
                        $data['dbType'] => $replication
                    );
                } catch (Exception $e) {
                    $serverInfo['replication'] = array(
                        'status'   => 'error',
                        'message'  => $e->getMessage()
                    );
                }
            }

            $data['servers'][] = $serverInfo;
        }

        $this->response->page('ui/db/manager/dashboard.js', $data, array('ui/monitoring/window.js'), array('ui/db/manager/dashboard.css'));
    }

    /**
     * @param   int     $farmRoleId
     * @param   int     $newSize        optional
     * @param   string  $volumeType     optional
     * @param   int     $iops           optional
     * @throws  Exception
     */
    public function xGrowStorageAction($farmRoleId, $newSize = 0, $volumeType = '', $iops = 0)
    {
        $this->request->restrictAccess(Acl::RESOURCE_DB_DATABASE_STATUS, Acl::PERM_DB_DATABASE_STATUS_MANAGE);

        $dbFarmRole = DBFarmRole::LoadByID($farmRoleId);
        $this->user->getPermissions()->validate($dbFarmRole->GetFarmObject());

        $textBehavior = $dbFarmRole->GetRoleObject()->getDbMsrBehavior();
        $behavior = Scalr_Role_Behavior::loadByName($textBehavior);
        $master = $behavior->getMasterServer($dbFarmRole);

        /* @var $master \DBServer */
        if ($master) {
            $port = $master->getPort(\DBServer::PORT_API);

            try {
                $volume = Scalr_Storage_Volume::init()->loadById(
                    $dbFarmRole->GetSetting(Scalr_Db_Msr::VOLUME_ID)
                );

                if ($volume->type != MYSQL_STORAGE_ENGINE::EBS && $volume->type != MYSQL_STORAGE_ENGINE::RAID_EBS && $volume->type != 'raid')
                    throw new Exception("Grow feature available only for EBS and RAID storage types");

                if ($newSize && $volume->size >= $newSize)
                    throw new Exception("New size should be greather than current one ({$volume->size} GB)");

                $volumeConfig = $volume->getConfig();
                $platformAccessData = PlatformFactory::NewPlatform($dbFarmRole->Platform)->GetPlatformAccessData($this->environment, $master);

                $newConfig = new stdClass();
                if ($newSize) {
                    $newConfig->size = $newSize;
                }

                if ($volumeType) {
                    $newConfig->volumeType = $volumeType;
                }

                if ($iops && ($volumeType == 'io1' || !$volumeType && $volumeConfig->volumeType == 'io1')) {
                    $newConfig->iops = $iops;
                }

                switch ($textBehavior) {
                    case ROLE_BEHAVIORS::REDIS:
                        $operationId = $master->scalarizr->redis->growStorage($volumeConfig, $newConfig, $platformAccessData);
                        break;
                    case ROLE_BEHAVIORS::POSTGRESQL:
                        $operationId = $master->scalarizr->postgresql->growStorage($volumeConfig, $newConfig, $platformAccessData);
                        break;
                    default:
                        $operationId = $master->scalarizr->mysql->growStorage($volumeConfig, $newConfig, $platformAccessData);
                        break;
                }

                $dbFarmRole->SetSetting(Entity\FarmRoleSetting::STORAGE_GROW_OPERATION_ID, $operationId);
                $dbFarmRole->SetSetting(Entity\FarmRoleSetting::STORAGE_GROW_SERVER_ID, $master->serverId);
                $dbFarmRole->SetSetting(Entity\FarmRoleSetting::STORAGE_GROW_LAST_ERROR, '');

                $this->response->data(['serverId' => $master->serverId, 'operationId' => $operationId]);

            } catch (Exception $e) {
                throw new Exception("Cannot grow storage: {$e->getMessage()}");
            }
        } else
            throw new Exception("Impossible to increase storage size. No running master server.");
    }

    public function xSetupPmaAccessAction()
    {
        $this->request->restrictAccess(Acl::RESOURCE_DB_DATABASE_STATUS, Acl::PERM_DB_DATABASE_STATUS_PMA);
        $this->request->defineParams(array(
            'farmId' => array('type' => 'int'),
            'farmRoleId' => array('type' => 'int')
        ));

        $dbFarm = DBFarm::LoadByID($this->getParam('farmId'));
        $this->user->getPermissions()->validate($dbFarm);

        $dbFarmRole = DBFarmRole::LoadByID($this->getParam('farmRoleId'));
        if ($dbFarmRole->FarmID != $dbFarm->ID)
            throw new Exception("Role not found");

        $dbFarmRole->ClearSettings("mysql.pma");

        $dbMsrBehavior = $dbFarmRole->GetRoleObject()->getDbMsrBehavior();
        if ($dbMsrBehavior) {
            $behavior = Scalr_Role_Behavior::loadByName($dbMsrBehavior);
            $masterDbServer = $behavior->getMasterServer($dbFarmRole);

            if ($masterDbServer) {
                $time = $dbFarmRole->GetSetting(Entity\FarmRoleSetting::MYSQL_PMA_REQUEST_TIME);
                if (!$time || $time + 3600 < time()) {
                    $msg = new Scalr_Messaging_Msg_Mysql_CreatePmaUser($dbFarmRole->ID, \Scalr::config('scalr.ui.pma.server_ip'));
                    $masterDbServer->SendMessage($msg);

                    $dbFarmRole->SetSetting(Entity\FarmRoleSetting::MYSQL_PMA_REQUEST_TIME, time(), Entity\FarmRoleSetting::TYPE_LCL);
                    $dbFarmRole->SetSetting(Entity\FarmRoleSetting::MYSQL_PMA_REQUEST_ERROR, "", Entity\FarmRoleSetting::TYPE_LCL);

                    $this->response->success();
                }
                else
                    throw new Exception("MySQL access credentials for PMA already requested. Please wait...");
            }
            else
                throw new Exception("There is no running MySQL master. Please wait until master starting up.");
        } else
            throw new Exception("PMA is not available for selected role. If you think this is mistake, please contact support.");
    }

    public function xCancelBackupAction()
    {
        $this->request->restrictAccess(Acl::RESOURCE_DB_DATABASE_STATUS, Acl::PERM_DB_DATABASE_STATUS_MANAGE);

        $this->request->defineParams(array(
            'farmId' => array('type' => 'int'),
            'farmRoleId' => array('type' => 'int')
        ));

        $dbFarm = DBFarm::LoadByID($this->getParam('farmId'));
        $this->user->getPermissions()->validate($dbFarm);

        $dbFarmRole = DBFarmRole::LoadByID($this->getParam('farmRoleId'));
        if ($dbFarmRole->FarmID != $dbFarm->ID)
            throw new Exception("Role not found");

        if ($dbFarmRole->GetSetting(Scalr_Db_Msr::DATA_BACKUP_IS_RUNNING) == 1) {
            $bundleServerId = $dbFarmRole->GetSetting(Scalr_Db_Msr::DATA_BACKUP_SERVER_ID);
            $operationId = $dbFarmRole->GetSetting(Scalr_Db_Msr::DATA_BACKUP_OPERATION_ID);

            $dbServer = DBServer::LoadByID($bundleServerId);


            if ($dbServer->IsSupported("0.23.0") && $operationId) {
                $dbServer->scalarizr->operation->cancel($operationId);
            } else
                $dbServer->SendMessage(new Scalr_Messaging_Msg_DbMsr_CancelBackup());
        }

        $this->response->success('Backup cancelation successfully initiated');
        return;
    }

    public function xCancelDataBundleAction()
    {
        $this->request->restrictAccess(Acl::RESOURCE_DB_DATABASE_STATUS, Acl::PERM_DB_DATABASE_STATUS_MANAGE);

        $this->request->defineParams(array(
            'farmId' => array('type' => 'int'),
            'farmRoleId' => array('type' => 'int')
        ));

        $dbFarm = DBFarm::LoadByID($this->getParam('farmId'));
        $this->user->getPermissions()->validate($dbFarm);

        $dbFarmRole = DBFarmRole::LoadByID($this->getParam('farmRoleId'));
        if ($dbFarmRole->FarmID != $dbFarm->ID)
            throw new Exception("Role not found");

        if ($dbFarmRole->GetSetting(Scalr_Db_Msr::DATA_BUNDLE_IS_RUNNING) == 1) {
            $bundleServerId = $dbFarmRole->GetSetting(Scalr_Db_Msr::DATA_BUNDLE_SERVER_ID);
            $operationId = $dbFarmRole->GetSetting(Scalr_Db_Msr::DATA_BUNDLE_OPERATION_ID);

            $dbServer = DBServer::LoadByID($bundleServerId);

            if ($dbServer->IsSupported("0.23.0") && $operationId) {
                $dbServer->scalarizr->operation->cancel($operationId);
            } else
                $dbServer->SendMessage(new Scalr_Messaging_Msg_DbMsr_CancelDataBundle());
        }

        $this->response->success('Data bundle cancelation successfully initiated');
        return;
    }

    public function xCreateDataBundleAction()
    {
        $this->request->restrictAccess(Acl::RESOURCE_DB_DATABASE_STATUS, Acl::PERM_DB_DATABASE_STATUS_MANAGE);

        $this->request->defineParams(array(
            'farmId' => array('type' => 'int'),
            'farmRoleId' => array('type' => 'int'),
            'bundleType'
        ));

        $dbFarm = DBFarm::LoadByID($this->getParam('farmId'));
        $this->user->getPermissions()->validate($dbFarm);

        $dbFarmRole = DBFarmRole::LoadByID($this->getParam('farmRoleId'));
        if ($dbFarmRole->FarmID != $dbFarm->ID)
            throw new Exception("Role not found");

        $behavior = Scalr_Role_Behavior::loadByName($dbFarmRole->GetRoleObject()->getDbMsrBehavior());
        $behavior->createDataBundle($dbFarmRole, array(
            'dataBundleType' => $this->getParam('bundleType'),
            'compressor' => $this->getParam('compressor'),
            'useSlave' => $this->getParam('useSlave'))
        );

        $this->response->success('Data bundle successfully initiated');
    }

    public function xCreateBackupAction()
    {
        $this->request->restrictAccess(Acl::RESOURCE_DB_DATABASE_STATUS, Acl::PERM_DB_DATABASE_STATUS_MANAGE);

        $this->request->defineParams(array(
            'farmId' => array('type' => 'int'),
            'farmRoleId' => array('type' => 'int')
        ));

        $dbFarm = DBFarm::LoadByID($this->getParam('farmId'));
        $this->user->getPermissions()->validate($dbFarm);

        $dbFarmRole = DBFarmRole::LoadByID($this->getParam('farmRoleId'));
        if ($dbFarmRole->FarmID != $dbFarm->ID)
            throw new Exception("Role not found");


        $behavior = Scalr_Role_Behavior::loadByName($dbFarmRole->GetRoleObject()->getDbMsrBehavior());
        $behavior->createBackup($dbFarmRole);

        $this->response->success('Backup successfully initiated');
    }

    /**
     * @param   int   $farmRoleId
     */
    public function xClearGrowStorageErrorAction($farmRoleId)
    {
        $dbFarmRole = DBFarmRole::LoadByID($farmRoleId);
        $this->user->getPermissions()->validate($dbFarmRole->GetFarmObject());

        $dbFarmRole->SetSetting(Entity\FarmRoleSetting::STORAGE_GROW_LAST_ERROR, null);
        $this->response->success();
    }
}
