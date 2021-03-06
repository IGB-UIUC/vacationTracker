<?php
/**
 * class AppointmentYearRules.php
 * Applies rules for appointment year leaves, this class extends Rules.php
 * 
 * @author nevoband
 *
 */
class AppointmentYearRules extends Rules
{
	const VACATION_LEAVE = 1;
	const SICK_LEAVE = 2;
	const NONCMLTV_SICK_LEAVE = 10;
	const FAMILY_MED_LEAVE = 7;
	const UNPAID_LEAVE = 9;
	const PARENT_LEAVE = 14;
	const BEREAV_LEAVE = 15;
	private $userInfo = null;

	/**
	 * Run rules for appointment year
	 * @see Rules::RunRules()
	 */
	public function RunRules($userId,$yearId, $userYearUsage=null)
	{
            $this->force = true;
		$hasRollOver=0;
		//Check if we just changed the previous year's leaves
		if($userYearUsage==null)
		{
                    
			//Since we didn't change we just load the year usage
			$userYearUsage = $this->LoadUserYearUsage($userId,$yearId);
		}

		//check if our user has been loaded from previous year run if not load it.
		if($this->userInfo==null)
		{
			$this->userInfo = new User($this->sqlDataBase);
			$this->userInfo->LoadUser($userId);
		}
		//Apply rules to leave
		$userYearUsage = $this->ApplyRules($userYearUsage);

		//Update the database with the modified leave values after rules were applied

		foreach($userYearUsage as $id=>$userYearUsageLeaveType)
		{
                    $thisyear = new Years($this->sqlDataBase, $yearId);
                    $startdate = $thisyear->getStartDate();
                    $enddate = $thisyear->getEndDate();
			//Check if anything needs to be updated or if used hours is still the same, mostly tested for performance reasons
			if($this->LoadCalcUsedHoursPayPeriod($yearId, $userYearUsageLeaveType['leave_type_id'], $userId, $startdate, $enddate)!=$userYearUsageLeaveType['used_hours'] || $this->force)
			{
				//Update database with new used hours
				$this->UpdateLeaveUserInfo($userId,$yearId,$userYearUsageLeaveType['used_hours'],$userYearUsageLeaveType['initial_hours'],$userYearUsageLeaveType['leave_type_id'],$userYearUsageLeaveType['leave_user_info_id']);
				//Check if we need to roll over to next year
				if($userYearUsageLeaveType['roll_over'])
				{
					$hasRollOver = 1;
				}
			}
		}
		//Attempt to load next year incase we need to update initial values due to roll over
		$nextYearId = $this->year->NextYearId($yearId);
		//Check if we need to apply roll over hours for next year
		if($nextYearId && ($hasRollOver || $this->force))
		{
			$nextYearUsage = $this->LoadUserYearUsage($userId,$nextYearId);

			if($hasRollOver)
			{
				foreach($nextYearUsage as $id=>$nextYearUsageLeaveType)
				{
					if($nextYearUsageLeaveType['roll_over'])
					{
                                            $nextYear = new Years($this->sqlDataBase, $nextYearId);
                                            // Get Max rollover from previous year and apply it to this year
                                            $currYear = new Years($this->sqlDataBase, $yearId);
                                            $currTypeId = $currYear->getYearType();
                                            $leaveType = $nextYearUsageLeaveType['leave_type_id'];
                                            $max_rollover = $currYear->GetMaxRollover($nextYearUsageLeaveType['leave_type_id']);
						$nextYearUsage[$id]['initial_hours'] = $userYearUsage[$id]['initial_hours']+$userYearUsage[$id]['added_hours']-$userYearUsage[$id]['used_hours'];
                                                if( $nextYearUsage[$id]['initial_hours'] > ( ($this->userInfo->getPercent()/100) * $max_rollover ) )
						{
							$nextYearUsage[$id]['initial_hours'] = ($this->userInfo->getPercent()/100) * $max_rollover;
						}
                                                // update database
                                                //echo("updated added hours ($leaveType) = ".$nextYearUsage[$id]['added_hours']."<BR>");
                                                //echo("updated used hours ($leaveType) = ".$nextYearUsage[$id]['used_hours']."<BR>");
                                                $this->UpdateLeaveUserInfo($userId, $nextYearId, $nextYearUsage[$id]['used_hours'], $nextYearUsage[$id]['initial_hours'], $nextYearUsage[$id]['leave_type_id'], $nextYearUsage[$id]['leave_user_info_id']);
					}
				}
			}
			//Since we applied roll over leaves to next year, we now need to rerun rules for next year.
			$this->RunRules($userId,$nextYearId,$nextYearUsage, true);
		}

	}

	/**
	 * Apply appointment year rules to the year inputed
	 * 
	 * @param unknown_type $userYearUsage
	 */
	private function ApplyRules($userYearUsage)
	{
		//Sick Days Rules
		//Check if we have a spill over to noncomltv hours
                // Update 07/16: only do this for current year, not future years.

		if($userYearUsage[self::SICK_LEAVE]['added_hours'] - $userYearUsage[self::SICK_LEAVE]['used_hours'] < 0)
		{	
			//Check if we have a spill over to initial comltv hours
			if($userYearUsage[self::SICK_LEAVE]['added_hours'] + $userYearUsage[self::NONCMLTV_SICK_LEAVE]['added_hours'] - $userYearUsage[self::SICK_LEAVE]['used_hours'] < 0)
			{
				//Spill over to initial comltv hours detected
				//Add all used horus to regular sick leave minus noncomltv added hours as we used all of them.
				$userYearUsage[self::SICK_LEAVE]['used_hours'] = $userYearUsage[self::SICK_LEAVE]['used_hours']-$userYearUsage[self::NONCMLTV_SICK_LEAVE]['added_hours'];
				$userYearUsage[self::NONCMLTV_SICK_LEAVE]['used_hours'] = $userYearUsage[self::NONCMLTV_SICK_LEAVE]['added_hours'];
				
			}
			else
			{
				//No spill to initial comltv sick leave detected
				//Add all added hours to used_hours of sick leave and the rest to noncomltv
                           	$userYearUsage[self::NONCMLTV_SICK_LEAVE]['used_hours'] = $userYearUsage[self::SICK_LEAVE]['used_hours'] - $userYearUsage[self::SICK_LEAVE]['added_hours'];
				$userYearUsage[self::SICK_LEAVE]['used_hours'] =  $userYearUsage[self::SICK_LEAVE]['added_hours'];
                                
				
			}
		}
		
		//Parental Leave
		//Charge parental leave from family medical leave as well as parental leave
		if($userYearUsage[self::PARENT_LEAVE]['used_hours'] > 0)
		{
			$userYearUsage[self::FAMILY_MED_LEAVE]['used_hours'] = $userYearUsage[self::FAMILY_MED_LEAVE]['used_hours'] + $userYearUsage[self::PARENT_LEAVE]['used_hours'];
		}

		return $userYearUsage;
	}
}

?>
