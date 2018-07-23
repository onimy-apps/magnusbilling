<?php
/**
 * =======================================
 * ###################################
 * MagnusBilling
 *
 * @package MagnusBilling
 * @author Adilson Leffa Magnus.
 * @copyright Copyright (C) 2005 - 2018 MagnusSolution. All rights reserved.
 * ###################################
 *
 * This software is released under the terms of the GNU Lesser General Public License v2.1
 * A copy of which is available from http://www.gnu.org/copyleft/lesser.html
 *
 * Please submit bug reports, patches, etc to https://github.com/magnusbilling/mbilling/issues
 * =======================================
 * Magnusbilling.com <info@magnusbilling.com>
 *
 */
class SmsCommand extends ConsoleCommand
{

    public $success;
    public $nameRoot    = 'rows';
    public $nameCount   = 'count';
    public $nameSuccess = 'success';
    public $nameMsg     = 'msg';

    public function run($args)
    {
        $UNIX_TIMESTAMP = "UNIX_TIMESTAMP(";

        $tab_day  = array(1 => 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday');
        $num_day  = date('N');
        $name_day = $tab_day[$num_day];

        echo $name_day;

        $filter = 'status = :key1 AND type = :key0  AND ' . $name_day . ' = :key1 AND startingdate <= :key2 AND expirationdate > :key2
                        AND  daily_start_time <= :key3 AND daily_stop_time > :key3';

        $params = array(
            ':key0' => 0,
            ':key1' => 1,
            ':key2' => date('Y-m-d H:i:s'),
            ':key3' => date('H:i:s'),

        );

        if (isset($args[1])) {
            $filter .= 'name = :campaignName';
            $params['campaignName'] = $args[1];
        }

        $modelCampaign = Campaign::model()->findAll(array(
            'condition' => $filter,
            'params'    => $params,
        ));

        if ($this->debug >= 1) {
            echo "\nFound " . count($modelCampaign) . " Campaign\n\n";
        }

        foreach ($modelCampaign as $campaign) {

            if ($this->debug >= 1) {
                echo "SEARCH NUMBER IN CAMPAIGN " . $campaign->name . "\n";
            }

            $nbpage = $campaign->frequency;

            //get all campaign phonebook
            $modelCampaignPhonebook = CampaignPhonebook::model()->findAll('id_campaign = :key', array(':key' => $campaign->id));
            $ids_phone_books        = array();
            foreach ($modelCampaignPhonebook as $key => $phonebook) {
                $ids_phone_books[] = $phonebook->id_phonebook;
            }

            $criteria = new CDbCriteria();
            $criteria->addInCondition('id_phonebook', $ids_phone_books);
            $criteria->addCondition('status = :key AND creationdate < :key1');
            $criteria->params[':key']  = 1;
            $criteria->params[':key1'] = date('Y-m-d H:i:s');
            $modelPhoneNumber          = PhoneNumber::model()->findAll($criteria);

            if ($this->debug >= 1) {
                echo 'Found ' . count($modelPhoneNumber) . ' Numbers in Campaign ' . "\n";
            }

            if (!count($modelPhoneNumber)) {
                if ($this->debug >= 1) {
                    echo "NO PHONE FOR CALL" . "\n\n\n";
                }

                continue;
            }

            foreach ($modelPhoneNumber as $sms) {
                if (date("s") > 55) {
                    exit;
                }
                $sms->idPhonebook->idUser->id_plan = $campaign->id_plan > 0 ? $campaign->id_plan : $sms->idPhonebook->idUser->id_plan;

                $id_user  = $sms->idPhonebook->idUser->id;
                $username = $sms->idPhonebook->idUser->username;
                $id_agent = $sms->idPhonebook->idUser->id_user;

                if (UserCreditManager::checkGlobalCredit($id_user) === false) {
                    if ($this->debug >= 1) {
                        echo " USER NO CREDIT FOR CALL " . $sms['username'] . "\n\n\n";
                    }

                    continue;
                }

                //print_r($sms->getAttributes());
                //print_r($campaign->getAttributes());

                $text = preg_replace("/\%name\%/", $sms->name, $campaign->description);

                if ($sms->number == '' || !is_numeric($sms->number)) {
                    PhoneNumber::model()->deleteByPk((int) $sms->id);
                    continue;
                }
                echo $sms->idPhonebook->idUser->username . " - " . $sms->number . " -" . $text . "\n";

                $res = SmsSend::send($sms->idPhonebook->idUser, $sms->number, $text);
                $sms->try++;
                $sms->status = isset($res['success']) && $res['success'] == true ? 3 : 2;
                $sms->save();
                $modelError = $sms->getErrors();
                if (count($modelError)) {
                    print_r($modelError);
                }
            }
        }
    }
}
