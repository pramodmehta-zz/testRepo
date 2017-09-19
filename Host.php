<?php
/**
* meeting.Host
*
* @author Virgil S. Palitang <virgilp@megameeting.com>
* @package meeting
*/

/**
 INCLUDES
*/
include_once INCPATH."BaseService.php";
include_once INCPATH."Teleconference.php";
include_once INCPATH."Balancer.php";
require_once INCPATH."CredentialMgrHelper.php";
/* if we start sending large amounts of mail, we'll have to start being more efficient than
just calling mail() which opens and closes an SMTP socket for each email. An alternative
would be to use the Pear::Net_SMTP package.
require_once('Net/SMTP.php');
*/
require_once "Mail.php";    // for sending via external mail server
/**
* Provide Host level services.
*
* Users with the role of 'host' or 'admin' will be able to access these methods.
* Please see the <b>meeting.Main</b> documentation for more on the authorization process.
* @author Virgil S. Palitang <virgilp@megameeting.com>
* @package meeting
*/
class Host extends BaseService {
	private $s_ServiceClass;
	private $dbh;
	private $dbx;
	private $telecon;
	private $timeOut;
	private $smtp;  // this will hold external SMTP config
	private $debugLog="/var/log/httpd/amfphp_debug_log";

	/**
	* Constructor
	*/
	function Host() {
		$this->dbh=$this->dbconnect();
		if (USE_SLAVE) { $this->dbx=$this->dbconnectSlave(); }
		else { $this->dbx=$this->dbh; }
		if ($this->dbh) { $this->s_ServiceClass="Host"; }
		if (!session_id() && !defined("NO_SESSIONS")) { session_start(); }
	}

	function beforeFilter() {
		$a_Allowed=array("host","admin","vhost");
		if (isset($_SESSION['role']) && in_array($_SESSION['role'],$a_Allowed)) { return true; }
		return false;
	}

	/**
	* Verify session authorization.
	* This method is deprecated. The beforeFilter() method now provides the necessary functionality
	* @return Boolean
	*/
	function isAuth() {
		return true;
	}

	/**
	* Get details of a specific AccountLogin.
	* Requires domain and username.
	* Returns a single AccountLogins record.
	* @param String Fully qualified domain name
	* @param String Username
	* @return Object
	*/
	function getAccountLogin($s_Domain,$s_UserName) {
		$db=$this->dbx;
		$a_Out=array();
		$s_Login=mysql_escape_string($s_UserName);
		$s_Sql=<<<END_QUERY
select Acc_DefaultUserPerms, Acc_EnableTeleconProfiles, a.Acc_EnableFMG, a.Acc_TeleconTwilioNumber,
b.*,
c.Version_ID, c.Acc_Region, c.Acc_Area, c.Acc_MSEnableLoadBalance, LBMS_Group,
c.Acc_MSDomain, c.Acc_MSApplication, c.Acc_MSEdgeSevers, c.Acc_EmailMode,
c.Acc_EmailSMTPAddress, c.Acc_EmailSMTPUsername, c.Acc_EmailSMTPPassword,
c.Acc_MSEODomain, c.Acc_MSEOApplication, c.Acc_MSEOEdgeList, c.Acc_MSEOThreshold,
c.Acc_MSEOPort, c.Acc_MSEOProtocol, c.Acc_MSEOOriginLimit, c.Acc_MSEORestrictVideoToHost,
c.Acc_MSEORestrictAudioToHost, c.Acc_EnableInvisibleHost, c.Acc_MaxRecordingAge,
c.Acc_MSClientToServer, c.Acc_MSServerToClient, c.Acc_MaxVideos, c.Acc_MaxAudio
from Accounts a, AccountLogins b, AccountSettingProfiles c
where a.Acc_ID=b.Acc_ID && b.Asp_ID=c.Asp_ID
&& Acc_Domain='$s_Domain' && AL_UserName='$s_Login' && AL_Active=1
END_QUERY;

		$res=mysql_query($s_Sql,$db);
		if ($res) {
			$a_Out=mysql_fetch_assoc($res);
			// updated 2011-07-07 Virgil S. Palitang. Account permissions must be considered as
			// well as AccountLogin permissions. We retrieve both, and filter out whatever does
			// not exist in both.
			mysql_free_result($res);
			if (count($a_Out)>1) {
				// user permissions
				$tmp=$this->compareTerms($a_Out['AL_DefaultUserPerms'], $a_Out['Acc_DefaultUserPerms']);
				$a_Out['AL_DefaultUserPerms']=$tmp;
				unset($a_Out['Acc_DefaultUserPerms']);
				// teleconferencing
				if ($a_Out['Acc_EnableTeleconProfiles']==0) { $a_Out['AL_EnableTeleconProfiles']=0; }
				$a_Out['VideoProfiles']=$this->getVideoProfiles($a_Out['AL_ID']);
				return $a_Out;
			}
			else {
				$this->log2file($this->s_ServiceClass,"getAccountLogin() got no results. SQL: $s_Sql\n");
			}
		}
		return $a_Out;
	}

	private function getVideoProfiles($alid) {
		$db=$this->dbx;
		$out=array();
		$sql="select a.VProfile_ID, VProfile_Name, VProfile_Width, VProfile_Height, VProfile_Rate, ".
		"VProfile_Compression,VProfile_Instances, VProfile_Unlimited, VProfile_MeetWide, ".
		"VProfile_OptToggle from VideoProfiles a, AL2VP b where a.VProfile_ID=b.VProfile_ID ".
		"&& AL_ID=$alid && VProfile_Active=1";
		$res=mysql_query($sql,$db);
		if ($res) {
			while(($row=mysql_fetch_assoc($res))!=false) { $out[]=$row; }
			mysql_free_result($res);
		}
		return $out;
	}

	/**
	* Fetch a host's snapshot.
	* A host has the option to store a snapshot that can be displayed in a meeting.
	* This method will return one of three values: -1, 0, or a ByteArray.
	* If no record exists for the specified host, -1 is returned.
	* If a zero-length record exists, 0 is returned.
	* If a record does have image data, a ByteArray is returned.
	*
	* Note that ByteArray is not native to PHP, and is only useful in the AMFPHP
	* context. Therefore, this method will likely be useless if the Host class
	* is instantiated outside of the AMFPHP context (API, utility, etc.)
	*
	* @param int AccountLogin ID.
	* @return mixed Number or binary image data.
	*/
	function getSnapshot($alid) {
		$db=$this->dbx;
		$out=-1;
		$sql = "select HBlob_Data from HostBlobs where AL_ID='$alid'";
		$res=mysql_query($sql,$db);
		if ($res) {
			$nr=mysql_num_rows($res);
			list ($row) = mysql_fetch_array($res);
			mysql_free_result($res);
			if (!$row) {
				$out = ($nr<1)? -1 : 0;
			} else {
				// ByteArray is defined in the AMFPHP core.
				// It is not a standard php object.
				$out = new ByteArray($row);
			}
		}
		return $out;
	}

	/**
	* Store snapshot for AccountLogin.
	* It is assumed that the second argument is a ByteArray containing
	* the image data (or empty ByteArray). If not, the operation fails
	* and returns false.
	*
	* Note also that ByteArray is not native to PHP, but provided by the
	* AMFPHP core to properly interface with AS3 requests. This means
	* that attempts to use this method outside the AMFPHP context (API,
	* utility, etc.) will likely fail.
	*
	* @param int AccountLogin ID.
	* @param ByteArray Image data.
	* @return Boolean True on success.
	*/
	function setSnapshot($alid, $imgData) {
		$db=$this->dbh;
		if (property_exists($imgData, "data")) {
			$px = mysql_real_escape_string($imgData->data);
		} else {
			return false;
		}
		$sql="replace into HostBlobs (AL_ID, HBlob_Data) values ('$alid', '$px') ";
		$trace="setSnapshot() Writing data.";
		$this->log2file($this->s_ServiceClass, $trace);
		$ok=mysql_query($sql, $db);
		if (!$ok) {
			$trace="setSnapshot() Error. ".mysql_error();
			$this->log2file($this->s_ServiceClass, $trace);
		}
		return $ok;
	}

	/**
	* Get part of an AccountLogin record.
	* When a host moves into a meetingroom, the AccountLogin data does not follow.
	* This method lets a host-authorized session retrieve desired data.
	*
	* It is only possible to retrieve info on the AccountLogin that is authorized
	* in the current session.
	* @param int Account Login ID.
	* @param Array List of desired fields
	* @return Object
	*/
	function getAccountLoginPart($n_ALID,$a_Cols) {
		$db=$this->dbx;
		$a_Out=array();
		$alprops=array( "AL_ID", "Acc_ID", "Asp_ID", "AL_UserName", "AL_Password", "AL_Active",
		"AL_Email", "AL_PhoneNumber", "AL_DateMask", "AL_TimeMask", "AL_HideWarnings", "AL_MeetingSeats",
		"AL_EnableVideoSettings", "AL_EnableAutoInviteSelf", "AL_DefaultCallInNumber",
		"AL_DefaultModeratorCode", "AL_DefaultAttendeeCode", "AL_DefaultVideoProfile",
		"AL_Reference", "AL_MSDefaultProtocol", "AL_MSDefaultPort", "AL_ModifyDateTime",
		"AL_AllowDefTeleconChange", "AL_LiveHelp", "AL_LiveHelpRandomEmail", "AL_RequireRegPayment",
		"AL_MaxRegistrants", "AL_EnableRecording", "AL_RecMBLimit");
		$cols="";
		// build query
		foreach ($a_Cols as $c) {
			// if (!in_array($c,$alprops)) { continue; }
			if ($c=='VideoProfiles') { continue; }
			if ($cols) { $cols.=","; }
			$cols.=$c;
		}

		if ($_SESSION['role']=='admin' || $_SESSION['role']=='vhost') {
			$sql="select $cols from AccountLogins where AL_ID=$n_ALID";
		} else {
			$chkUser=mysql_escape_string($_SESSION['user']);
			$sql="select $cols from AccountLogins where AL_ID=$n_ALID && AL_UserName='$chkUser' && AL_Active=1";
		}
		$this->log2file($this->s_ServiceClass,"getAccountLoginPart() debug. SQL: $sql\n");
		$res=mysql_query($sql,$db);
		if ($res) {
			$a_Out=mysql_fetch_assoc($res);
			mysql_free_result($res);
		}
		// handle VideoProfiles
		if (in_array("VideoProfiles",$a_Cols)) {
			$a_Out['VideoProfiles']=$this->getVideoProfiles($n_ALID);
		}
		// filter permissions
		if (in_array("AL_EnableTeleconProfiles",$a_Cols)) {
			$sql="select Acc_EnableTeleconProfiles from Accounts a, AccountLogins b where ".
			"a.Acc_ID=b.Acc_ID && AL_ID=$n_ALID";
			$res=mysql_query($sql,$db);
			if ($res) {
				list($tmp)=mysql_fetch_array($res);
				$a_Out["AL_EnableTeleconProfiles"]=$tmp;
				mysql_free_result($res);
			}
		}
		if (in_array("AL_DefaultUserPerms",$a_Cols)) {
			$sql="select Acc_DefaultUserPerms from Accounts a, AccountLogins b where ".
			"a.Acc_ID=b.Acc_ID && AL_ID=$n_ALID";
			$this->log2file($this->s_ServiceClass,"getAccountLoginPart() debug2. SQL: $sql");

			$res=mysql_query($sql,$db);
			if ($res) {
				$row=mysql_fetch_assoc($res);
				mysql_free_result($res);
			}
			$tmp=$this->compareTerms($a_Out['AL_DefaultUserPerms'],$row['Acc_DefaultUserPerms']);
			$a_Out['AL_DefaultUserPerms']=$tmp;
		}
		return $a_Out;
	}

	/**
	* Get a list of meetings and associated registrants.
	* Requires a valid account login ID.
	* Returns a list of active meetings that require registration and includes
	* list of registrants.
	* The return is an array of objects that follow the structure:<code>
	* Meet_ID:int
	* Meet_Name:String
	* RegistrantData:Array
	* </code>
	*
	* It is possible that 'RegistrantData' will be empty. Otherwise it will
	* contain more objects that follow the structure:<code>
	* Reg_UserID:int
	* Reg_Email:String
	* Reg_RegisterTime:String
	* Reg_UserPaid:int
	* </code>
	*
	* @param int AccountLogin ID
	* @return Array List of objects. See description for object structure.
	*/
	function getMeetWithReg($alid) {
		$db=$this->dbh;
		$sql="select a.Meet_ID, Meet_Name, Reg_UserID, Reg_Email, Reg_RegisterTime, Reg_UserPaid ".
		"from Meetings a left join Registration b on a.Meet_ID=b.Meet_ID where Meet_EnableJoin=1 ".
		"&& Meet_RequireRegistration=1 && AL_ID='$alid'";
		$out=array();
		$mid=0;
		$res=mysql_query($sql,$db);
		if ($res) {
			while(($row=mysql_fetch_assoc($res))!=false) {
				if ($mid != $row['Meet_ID']) {
					if (count($newObj)) { $out[]=$newObj; }
					$newObj=array(
						"Meet_ID" => $row['Meet_ID'],
						"Meet_Name" => $row['Meet_Name'],
						"RegistrantData" => array()
					);

					$mid=$row['Meet_ID'];
				}
				if(isset($row['Reg_UserID'])) {
					$newObj["RegistrantData"][]=array(
						"Reg_UserID" => $row["Reg_UserID"],
						"Reg_Email" => $row["Reg_Email"],
						"Reg_RegisterTime" => $row["Reg_RegisterTime"],
						"Reg_UserPaid" => $row["Reg_UserPaid"]
					);
				}
			}
			$out[]=$newObj;
			mysql_free_result($res);
		}
		return $out;
	}

	/**
	* Update AccountLogin record.
	* Requires object containing properties Acc_ID and AL_ID.
	* All properties must have respective columns in the AccountLogins table.
	* Sample JSON string:<code>{"Acc_ID":321,"AL_Password":"mySecret"...}</code>
	* Returns true on success, false on failure.
	* @param Object
	* @return Boolean
	*/
	function updateAccountLogin($a_AccountLogin) {
		$db=$this->dbh;
		$b_Out=false;
		// these variables are only used if AL_MeetingSeats is set.
		$sctOld=0;
		$sctNew=0;
		$sctFlag=0;
		$canUpd = $this->getCols("AccountLogins", array("Acc_ID","AL_ID","AL_ModifyDateTime"));

		if (!isset($a_AccountLogin["AL_ID"])) {
			$err="Required field (AL_ID) missing.";
			$this->log2file($this->s_ServiceClass,"updateAccountLogin() - $err");
			if (defined("THROW_ERRORS")) { throw new Exception($err); }
			return $b_Out;
		}
		$alid=$a_AccountLogin['AL_ID'];
		if (isset($_SESSION['acct'])) { $acct=$_SESSION['acct']; }
		else if (isset($a_AccountLogin['Acc_ID'])) { $acct=$a_AccountLogin['Acc_ID']; }
		else {
			$err="Unable to determine Account ID.";
			$this->log2file($this->s_ServiceClass,"updateAccountLogin() - $err");
			if (defined("THROW_ERRORS")) { throw new Exception($err); }
			return $b_Out;
		}

		// To enforce inherited fields, we first determine Account values.
		$acc2sub=array(
			"Acc_EnableRecording"=>'AL_EnableRecording',
			"Acc_EnableTeleconTollFree"=>'AL_EnableTeleconTollFree',
			"Acc_EnableTeleconToll"=>'AL_EnableTeleconToll',
			"Acc_EnableFMG"=>'AL_EnableFMG',
			"Acc_RecMBLimit"=>'AL_RecMBLimit',
			"Acc_Seats"=>'AL_MeetingSeats'
		);
		$cols=implode(',',array_keys($acc2sub)).",AL_MeetingSeats";
		// If password set to change, do some validation shortly
		if (isset($a_AccountLogin['AL_Password'])) { $cols .= ",AL_Password"; }

		$sql="select $cols from AccountLogins a, Accounts b where a.Acc_ID=b.Acc_ID ".
		"&& a.Acc_ID=$acct && AL_ID=$alid";
		$res=mysql_query($sql,$db);
		if ($res) {
			$acctFilter=mysql_fetch_assoc($res);
			mysql_free_result($res);
		}
		if (isset($acctFilter['AL_MeetingSeats'])) {
			$sctOld = intval($acctFilter['AL_MeetingSeats']);
		} else {
			$err="Account/Login mismatch. Invalid update.";
			$this->log2file($this->s_ServiceClass,"updateAccountLogin() - $err");
			if (defined("THROW_ERRORS")) { throw new Exception($err); }
			return $b_Out;
		}
		// enforce limits
		$msg="";
		foreach ($acc2sub as $k=>$v) {
			if (isset($a_AccountLogin[$v]) && $a_AccountLogin[$v] > $acctFilter[$k]) {
				// $a_AccountLogin[$v]=$acctFilter[$k];
				$msg.="Can't set $v over ".$acctFilter[$k].". ";
			}
		}
		if ($msg) {
			$err=trim("Limits exceeded. $msg");
			$this->log2file($this->s_ServiceClass,"updateAccountLogin() - $err");
			if (defined("THROW_ERRORS")) { throw new Exception($err); }
			return $b_Out;
		}

		// set flag for seat count inheritance.
		if (isset($a_AccountLogin['AL_MeetingSeats']) && $a_AccountLogin['AL_MeetingSeats']!=$sctOld) {
			$sctFlag=1;
			$sctNew=$a_AccountLogin['AL_MeetingSeats'];
		}

		// Do password validation
		if (isset($a_AccountLogin['AL_Password'])) {
			$newPass = $a_AccountLogin['AL_Password'];
			$oldPass = $acctFilter['AL_Pasword'];
			// make sure new one is valid
			if (strlen($newPass) < 1) {
				$err = "updateAccountLogins() failed. Invalid password.";
				$this->log2file($this->s_ServiceClass,$err);
				if (defined("THROW_ERRORS")) { throw new Exception($err); }
				return $b_Out;
			}
			// no dupes
			/*
			if ($this->verifyHash($newPass, $oldPass) || $newPass==$oldPass) {
				$err = "updateAccountLogins() failed. New password must not match old.";
				$this->log2file($this->s_ServiceClass,$err);
				if (defined("THROW_ERRORS")) { throw new Exception($err); }
				return $b_Out;
			}
			*/

			// hash new password
			$a_AccountLogin['AL_Password'] = $this->createHash($newPass);
			$a_AccountLogin['AL_PasswordMustChange'] = 0;
		}
		/*
		debugging
		$s_Data=print_r($a_AccountLogin,true);
		$this->log2file($this->s_ServiceClass,"updateAccountLogin() debugging data:\n$s_Data");
		*/

		// if all ok, build query
		$s_UpdString="AL_ModifyDateTime=utc_timestamp()";
		$s_WhereClause='where';
		foreach ($a_AccountLogin as $key=>$val) {
			if ($key=="Acc_ID" || $key=="AL_ID") {
				if (strpos($s_WhereClause," ")!==false) { $s_WhereClause.=" &&"; }
				$s_WhereClause.=" $key=$val";
			} elseif (in_array($key, $canUpd)) {
				$s_Tmp=mysql_real_escape_string($val,$db);
				$s_UpdString.=",$key='$s_Tmp'";
			}
		}

		$s_Sql="update AccountLogins set $s_UpdString $s_WhereClause";

		/*
		more debugging
		$this->log2file($this->s_ServiceClass,"updateAccountLogin() debugging SQL: $s_Sql\n");
		*/

		// Execute!
		if (mysql_query($s_Sql,$db)) {
			$this->log2file($this->s_ServiceClass,"updateAccountLogin() successful. SQL: $s_Sql\n");
			$b_Out=true;
			if ($sctFlag) {
				// meetings inherit new number...
				/*
				// bug 1023 says "no, they don't..." - begin bug 1023 block
				$qqq="update Meetings set Meet_Seats='$sctNew' where AL_ID='$alid'";
				mysql_query($qqq,$db);
				// end bug 1023 block
				*/

				// and log the changes too.
				$qqq="insert into SeatChanges (AL_ID,SC_Date,SC_SeatsOld,SC_SeatsNew, SC_RunningTotal) ".
				"select '$alid',utc_timestamp(),'$sctOld','$sctNew', sum(AL_MeetingSeats) from ".
				"AccountLogins where Acc_ID='$acct' && AL_Active='1'";
				if (!mysql_query($qqq,$db)) {
					$trace="updateAccountLogin() - sql: $qqq\n".mysql_error($db);
					$this->log2file($this->s_ServiceClass, $trace);
				}
			}
		} else {
			$out="updateAccountLogin() failed. SQL: $s_Sql\n".mysql_error($db);
		}
		return $b_Out;
	}

	/**
	* Get the max allowed seats for this account.
	* Requires valid account ID. Returns whole number.
	* @param int
	* @return int
	*/
	private function accountSeats($account) {
		$db=$this->dbx;
		$seats=0;
		$sql="select Acc_Seats from Accounts where Acc_ID=$account";
		$res=mysql_query($sql,$db);
		if($res) {
			list($seats)=mysql_fetch_array($res);
			mysql_free_result($res);
		}
		return $seats;
	}

	/**
	* Create a new Meeting record.
	* Create a Meeting by sending object with properties:<ul>
	* <li>Meet_Name:String *required - </li>
	* <li>Acc_ID:int *required - </li>
	* <li>AL_ID:int *required - </li>
	* <li>Meet_ScheduledDateTime:String - Scheduled UTC time of meeting. Format should be YYYY-MM-DD hh:mm:ss</li>
	* <li>TzOffset:int - Host's offset from UTC in minutes.</li>
	* <li>Meet_EnableAutoAccept:Boolean - Auto-accept attendees.</li>
	* <li>Meet_EnableChat:Boolean - Allow text chat.</li>
	* <li>Meet_EnableMeetingList:Boolean - Show meeting in the domain meeting list.</li>
	* <li>Meet_EnablePersistChat:Boolean - Save chat history.</li>
	* <li>Meet_EnablePrivateChat:Boolean - Enable private chat.</li>
	* <li>Meet_EnableUserList:Boolean - Show user list in meeting.</li>
	* <li>Meet_DefaultUserPerms:String - Comma-separated list. Sets default user permissions.</li>
	* <li>Meet_ExpireDateTime:String - UTC date time of expiration.</li>
	* <li>Meet_InviteComments:String - Text</li>
	* <li>Meet_MSApplication:String - Name of the media server application.</li>
	* <li>Meet_MSDefaultProtocol:String - Protocol to use when connecting to the media server.</li>
	* <li>Meet_MSDomain:String - Media server name.</li>
	* <li>Meet_MSEdgeServers:String - Edge server names.</li>
	* <li>Meet_MaxAudio:int - Maximum audio streams.</li>
	* <li>Meet_MaxVideos:int - Maximum video streams. Should never be greater than 16.</li>
	* <li>Meet_Mode:int - Related to limiting audio and video streams.</li>
	* <li>Meet_Password:String - Meeting password.</li>
	* <li>Meet_RequireEmail:Boolean - Require attendees to submit an email.</li>
	* <li>Meet_RequirePhoneNumber:Boolean - Require attendees to submit a phone number.</li>
	* <li>Meet_RequireRegistration:Boolean - Require users to register before entering.</li>
	* <li>Meet_RequireRegPayment:Boolean - Set payment requirement in registration.</li>
	* <li>Meet_RegistrationFee:String - Amount to charge for registration. Default=0.00</li>
	* <li>Meet_RegistrationCurrency:Boolean - Currency of registration fee. Default=USD</li>
	* <li>Meet_Seats:int - If set to zero (default), the number is automatically
	* limited by the account login setting. Cannot exceed the seat count of the account.</li>
	* <li>Meet_TollConferenceMode:String - Identifies the teleconferencing mode.</li>
	* <li>Meet_CallInNumber:String - If TollConferenceMode is set, this field is required.</li>
	* <li>Meet_AttendeeCode:String - Attendee access code for teleconference.</li>
	* <li>Meet_ModeratorCode:String - Moderator access code for teleconference.</li>
	* </ul>
	*
	* Notes: If the Meet_DefaultUserPerms property is not set, it will default to:
	* "ReceiveAudio,RecieveVideo,VidLayout,Chat".
	*
	* Returns new Meeting ID on success, <i>Failed</i> otherwise.
	* @param Object
	* @return String
	*/
	function createMeeting($a_Meeting) {
		$db=$this->dbh;
		$b_Out="Failed";
		$a_Reqd=array("Meet_Name"=>0,"Acc_ID"=>0,"AL_ID"=>0);
		$s_Cols='';
		$s_Vals='';
		$errs='';
		$tzoffset=$a_Meeting['TzOffset'];
		$noNeed = array("TzOffset", "Meet_ID", "Meet_CreateDateTime",
		"Meet_ScheduledDateTime", "Meet_EndDateTime", "Meet_ExpireDateTime");
		$myCols = $this->getCols("Meetings", $noNeed);

		// load some defaults and max values
		$myvals=$this->getMyDefaults($a_Meeting['AL_ID']);

		$s_Cols='Meet_CreateDateTime';
		//,Meet_ExpireDateTime,Meet_ScheduledDateTime';
		$s_Vals='utc_timestamp()';
		//,date_add(utc_timestamp(),interval 6 month)';
		// If Meet_ScheduledDateTime was passed in, use it, otherwise use the default.
		$s_Cols.=",Meet_ScheduledDateTime";
		if (isset($a_Meeting['Meet_ScheduledDateTime'])) {
			// - useless - $a_Reqd['Meet_ScheduledDateTime']=1;
			// need to convert the input to UTC
			// Meet_ScheduledDateTime format=YYYY-MM-DD hh:mm:ss
			list($sdt,$stm)=explode(" ",$a_Meeting['Meet_ScheduledDateTime']);
			list($yy,$mo,$dy)=explode("-",$sdt);
			list($hh,$mm,$ss)=explode(":",$stm);
			$ltm=gmmktime($hh,$mm,$ss,$mo,$dy,$yy) + ($tzoffset*60);
			$utm=gmdate("Y-m-d H:i:s",$ltm);
			// $s_Vals.=",from_unixtime($ltm)";
			$s_Vals.=",'$utm'";
		} else {
			$ltm = gmmktime();
			$s_Vals.=',utc_timestamp()';
		}
		// If Meet_EndDateTime was passed in, use it.
		if (isset($a_Meeting['Meet_EndDateTime'])) {
			$s_Cols.=",Meet_EndDateTime";
			// need to convert the input to UTC
			// Meet_EndDateTime format=YYYY-MM-DD hh:mm:ss
			list($sdt,$stm)=explode(" ",$a_Meeting['Meet_EndDateTime']);
			list($yy,$mo,$dy)=explode("-",$sdt);
			list($hh,$mm,$ss)=explode(":",$stm);
			$ltm=gmmktime($hh,$mm,$ss,$mo,$dy,$yy) + ($tzoffset*60);
			$utm=gmdate("Y-m-d H:i:s",$ltm);
			// $s_Vals.=",from_unixtime($ltm)";
			$s_Vals.=",'$utm'";
		}
		// prep VirtualDoorway check.
		if (!isset($a_Meeting['Meet_EnableAutoAccept'])) { $a_Meeting['Meet_EnableAutoAccept']=1; }
		// if expire date is set, use it
		$s_Cols.=",Meet_ExpireDateTime";
		if (isset($a_Meeting['Meet_ExpireDateTime'])) {
			list($sdt,$stm)=explode(" ",$a_Meeting['Meet_ExpireDateTime']);
			list($yy,$mo,$dy)=explode("-",$sdt);
			list($hh,$mm,$ss)=explode(":",$stm);
			$xtm=gmmktime($hh,$mm,$ss,$mo,$dy,$yy) + ($tzoffset*60);
			$utmx=gmdate("Y-m-d H:i:s",$xtm);
			$s_Vals.=",'$utmx'";
		} else {
			$exm = 6;
			// virtual doorway meetings get 12 months to expire.
			if ($myvals['Acc_EnableMeetUsNow']>0 && !$a_Meeting['Meet_EnableAutoAccept']) { $exm=12; }
			if (isset($a_Meeting['Meet_ScheduledDateTime'])) {
				list($sdt,$stm)=explode(" ",$a_Meeting['Meet_ScheduledDateTime']);
				list($yy,$mo,$dy)=explode("-",$sdt);
				list($hh,$mm,$ss)=explode(":",$stm);
				$ltm=gmmktime($hh,$mm,$ss,$mo,$dy,$yy) + ($tzoffset*60);
				$utm=gmdate("Y-m-d H:i:s",$ltm);
				$s_Vals.=",date_add('$utm',interval $exm month)";
			} else {
				$s_Vals.=",date_add(utc_timestamp(),interval $exm month)";
			}
		}

		// Enforce minimum meeting name length
		if (strlen($a_Meeting['Meet_Name'])<3) {
			$errs="Insufficient meeting name length. (".$a_Meeting['Meet_Name'].")";
			$this->log2file($this->s_ServiceClass,"createMeeting() $errs\n");
			if (defined("THROW_ERRORS")) { throw new Exception($errs); }
			return $b_Out;
		}

		// bug 1023 - deprecating Meet_Seats. Comment this out when implementing.
		// begin 1023 block
		// $stMax=$myvals['AL_MeetingSeats'];
		// if (!isset($a_Meeting['Meet_Seats'])) { $a_Meeting['Meet_Seats']=$stMax; }
		// end 1023 block
		if (!isset($a_Meeting['Meet_Seats'])) { $a_Meeting['Meet_Seats']=0; }
		$stAlloc = $myvals['AL_MeetingSeats'];
		$a_Meeting['Meet_MSDefaultPort'] = $myvals['AL_MSDefaultPort'];

		if (!isset($a_Meeting['Meet_EnableJoin'])) { $a_Meeting['Meet_EnableJoin']=1; }
		if (!isset($a_Meeting['Meet_EnableDepo'])) { $a_Meeting['Meet_EnableDepo']=0; }
		if (!isset($a_Meeting['Meet_ExpectedAttendees'])) { $a_Meeting['Meet_ExpectedAttendees']=$stAlloc; }
		if (!isset($a_Meeting['Meet_NoiseCancelCtrl'])) { $a_Meeting['Meet_NoiseCancelCtrl'] = 0; }
		if (!isset($a_Meeting['Meet_SkinID']) && $myvals['AL_DefaultMeetSkin']>0 ) {
			$a_Meeting['Meet_SkinID'] = $myvals['AL_DefaultMeetSkin']; }
		if (!isset($a_Meeting['Meet_MSDefaultProtocol'])) {
			$a_Meeting['Meet_MSDefaultProtocol'] = $myvals['AL_MSDefaultProtocol']; }
		if (!isset($a_Meeting['Meet_MSApplication'])) {
			$a_Meeting['Meet_MSApplication'] = $myvals['Acc_MSApplication']; }
		if (!isset($a_Meeting['Meet_EnableMeetingList'])) {
			$a_Meeting['Meet_EnableMeetingList'] = $myvals['Acc_EnableBusinessBundle']; }
		if (!isset($a_Meeting['Meet_EnableUserList'])) { $a_Meeting['Meet_EnableUserList']=1; }
		if (!isset($a_Meeting['Meet_HostUserName'])) {
			$a_Meeting['Meet_HostUserName'] = $myvals['AL_UserName']; }
		if (!isset($a_Meeting['Meet_MaxVideos'])) {
			if ($stAlloc<17) { $tmpv=16; }
			elseif ($stAlloc<18) { $tmpv=14; }
			elseif ($stAlloc<20) { $tmpv=12; }
			elseif ($stAlloc<25) { $tmpv=10; }
			elseif ($stAlloc<35) { $tmpv=6; }
			elseif ($stAlloc<50) { $tmpv=4; }
			elseif ($stAlloc<100) { $tmpv=2; }
			else { $tmpv=1; }
			$a_Meeting['Meet_MaxVideos'] = $tmpv;
		}

		if ($a_Meeting['Meet_MaxVideos']>$myvals['Acc_MaxVideos']) {
			$a_Meeting['Meet_MaxVideos'] = $myvals['Acc_MaxVideos'];
		}
		if (!isset($a_Meeting['Meet_DepoTranscriptRate'])) {
			$a_Meeting['Meet_DepoTranscriptRate'] = $myvals['Acc_DepoTranscriptRate'];
		}
		if (!isset($a_Meeting['Meet_DepoAVRate'])) {
			$a_Meeting['Meet_DepoAVRate'] = $myvals['Acc_DepoAVRate'];
		}

		// bug 2846 - force port 443 if protocol is rtmps
		if ($a_Meeting['Meet_MSDefaultProtocol'] == "rtmps") { $a_Meeting['Meet_MSDefaultPort'] = 443; }

		if (isset($a_Meeting['Logo_ID'])) {
			$logo=$a_Meeting['Logo_ID'];
		// get appropriate RegPageStyle
			$pgStyle=$this->getRegPageStyle($a_Meeting['Acc_ID'], $a_Meeting['AL_ID'], $logo);
			if ($pgStyle<1) {
				$errs="Database error. Could not set registration style.";
				$this->log2file($this->s_ServiceClass,"createMeeting() $errs\n");
				if (defined("THROW_ERRORS")) { throw new Exception($errs); }
				// currently not a fatal error, but uncomment the next 2 if it should be.
				// $b_Out.=". $errs";
				// return $b_Out;
			} else {
				$a_Meeting['Meet_RegStyleID']=$pgStyle;
			}
			unset($a_Meeting['Logo_ID']);
		}

		// validate telconference values
		/*
		if (isset($a_Meeting['Meet_TollConferenceMode'])) {
			$tcmode=$a_Meeting['Meet_TollConferenceMode'];
			if ($tcmode=='toll' || $tcmode=='tollfree') {
				// blank call-in number, or blank attendee code voids toll conference mode
				if (!$a_Meeting['Meet_CallInNumber'] || !$a_Meeting['Meet_AttendeeCode']) {
					$a_Meeting['Meet_TollConferenceMode']='';
				}
			}
		}
		*/

		// video profile
		if(!isset($a_Meeting['Meet_VideoProfile'])) {
			$a_Meeting['Meet_VideoProfile']=$myvals['AL_DefaultVideoProfile'];
		}

		// default permissions
		if (!isset($a_Meeting['Meet_DefaultUserPerms'])) {
			$a_Meeting['Meet_DefaultUserPerms']='ReceiveAudio,ReceiveVideo,VidLayout,Chat';
		}

		// discovered that 'Meet_MSEdgeServers' might exist, but is empty. So test
		// length of value, not existence.
		$fmsedge = (isset($a_Meeting['Meet_MSEdgeServers']))? $a_Meeting['Meet_MSEdgeServers']:"";

		/* Bug 4001/4010. Allow API users to set FMS.
		* (4001)
		* The regular Host UI will always set Meet_MSDomain based on Acc_MSDomain,
		* so if it varies here, we will treat it as an API call and allow it to
		* explicitly set Meet_MSDomain.
		*
		* (4010)
		* The better solution is to modify the Host swf so that Meet_MSDomain is not
		* set if the account is load-balanced. That way, there is no question whether
		* or not to explicitly set the value. If it's set, it's set, if not, then
		* perform load-balancing as needed.
		*/
		// determine load balancing if necessary + provide default media server
		$lbc=new Balancer($db);
		$msdomain = (isset($a_Meeting['Meet_MSDomain']))? $a_Meeting['Meet_MSDomain'] : "";

		/* ok, NOW - let's review the criteria for load balancing
		- Load-balancing enabled.
		- Meet_MSDomain not set (or empty)
		- FMS edge servers not set (or empty)

		* No need for balancing if either Meet_MSDomain or fmsedge is set.
		*/
		if (strlen($msdomain)<1) {
			if (strlen($fmsedge)<1) {
				if ($myvals['Acc_MSEnableLoadBalance']>0) {
					$grp=$myvals['LBMS_Group'];
					// invoke the balancer class
					$msdomain = $lbc->getLBMS($utm,$grp);
				} else {
					// not load-balanced, but empty Meet_MSDomain - use default
					/* - Danger! Danger!
					* Currently over 800 active accounts where Acc_MSDomain='' and
					* Acc_MSEnableLoadBalance = 0.
					* Use "USA Load Group" if default is empty.
					*/
					if (strlen($myvals['Acc_MSDomain'])>1) { $msdomain = $myvals['Acc_MSDomain']; }
					else { $msdomain = $lbc->getLBMS($utm, "USA Load Group"); }
				}
				$a_Meeting['Meet_MSDomain'] = $msdomain;
			} else {
				// having empty Meet_MSDomain but filled Meet_MSEdgeServers
				// is a very bad thing.
				$errs="Invalid config. Edge servers defined without origin.";
				$this->log2file($this->s_ServiceClass,"createMeeting() $errs\n");
				if (defined("THROW_ERRORS")) { throw new Exception($errs); }
				return $b_Out;
			}
		}


/*
		$trace="createMeeting() debug. LB=".$myvals['Acc_MSEnableLoadBalance'].", ".
			$a_Meeting['Meet_MSDomain'].". ".$lbc->debugMsg();
		$this->log2file($this->s_ServiceClass,$trace);
*/

		// build values array
		foreach ($a_Meeting as $column=>$value) {
			/* use the new getCols() result...
			if ($column=="TzOffset") { continue; }  // not part of the meeting record
			if ($column=="Meet_CreateDateTime") { continue; }   // already taken care of
			if ($column=="Meet_ScheduledDateTime") { continue; }    // already taken care of
			if ($column=="Meet_ExpireDateTime") { continue; }   // already taken care of
			*/
			if (in_array($column, $myCols)) {
				if(array_key_exists($column,$a_Reqd)) { $a_Reqd[$column]++; }
				$s_Cols.=",$column";
				$s_Temp=mysql_real_escape_string($value,$db);
				$s_Vals.=",'$s_Temp'";
			}
		}
		// make sure required columns are filled
		foreach ($a_Reqd as $column=>$value) {
			if($value==0) { $errs.="Missing value for $column. "; }
		}
		if($errs) {
			$this->log2file($this->s_ServiceClass,"createMeeting() $errs\n");
			if (defined("THROW_ERRORS")) { throw new Exception($errs); }
			return $b_Out;
		}

		// see if there is a duplicate
		$mtgExists=$this->chkMeetings($a_Meeting["Meet_Name"],$a_Meeting["AL_ID"]);
		if ($mtgExists) {
			$errs="An active meeting named '".$a_Meeting['Meet_Name'].
			"' already exists for AL_ID: ".$a_Meeting['AL_ID'].".";
			$this->log2file($this->s_ServiceClass,"createMeeting() - $errs\n");
			if (defined("THROW_ERRORS")) { throw new Exception($errs); }
			return $b_Out;
		}

		// create!
		$s_Sql="insert into Meetings ($s_Cols) values ($s_Vals)";
		if (mysql_query($s_Sql,$db)) {
			$b_Out=mysql_insert_id($db);
			$this->log2file($this->s_ServiceClass,"createMeeting() succeeded. New Meet_ID: $b_Out.");
//          $this->log2file($this->s_ServiceClass,"createMeeting() debug - SQL: $s_Sql","/var/log/httpd/amfphp_debug_log");
			// bug 3741 - stream manager keys without having to mail
			if ($a_Meeting['Meet_EnableDepo']>0 && isset($a_Meeting['Meet_DepoHostInvites'])) {
				$this->currentStreamManagers($b_Out, $a_Meeting['Meet_DepoHostInvites']);
			}

			//If this is a twilio meeting, update the Meet_ModeratorCode and Meet_AttendeeCode to match the meeting id
			if ($a_Meeting['Meet_TollConferenceMode'] == "twilio") {
				$s_Sql="Update Meetings SET Meet_ModeratorCode = '$b_Out', Meet_AttendeeCode = '$b_Out' ".
				" WHERE Meet_ID = $b_Out";
				mysql_query($s_Sql,$db);
			}
		} else {
			$err="createMeeting() failed. SQL: $s_Sql Error: ".mysql_error($db);
			$this->log2file($this->s_ServiceClass,$err);
			if (defined("THROW_ERRORS")) {
				throw new Exception("createMeeting() failed. Check error log for more details.");
			}
		}
		return $b_Out;
	}

	/**
	* Update Meetings record.
	* Requires Meeting object with Meet_ID property.
	* Meet_ID is not and cannot be updated, but is required for a proper database update.
	* Returns true on success, false on failure.
	* @param Object
	* @return mixed Full meeting record on success, false otherwise.
	*/
	function updateMeeting($a_Meeting) {
		$db=$this->dbh;
		$b_Out=false;
		if (!array_key_exists("Meet_ID",$a_Meeting) ) {
			$s_Data="updateMeeting() Missing Meet_ID field.\n";
			$s_Data.=print_r($a_Meeting,true);
			$this->log2file($this->s_ServiceClass,$s_Data);
			if (defined("THROW_ERRORS")) { throw new Exception("updateMeeting() Missing Meet_ID field."); }
			return $b_Out;
		}

		// 'protected' columns
		// Updated 13 Nov 2012. Virgil S. Palitang
		// Bug 1888 - need to allow changes to Meet_MSApplication, Meet_MSEdgeServers
		// and Meet_MSDomain.
		// $doNotChange=array("Acc_ID","AL_ID","Meet_MSApplication","Meet_MSEdgeServers");
		$doNotChange=array("Acc_ID","AL_ID");

		$err='';
		foreach ($doNotChange as $col) {
			if (array_key_exists($col,$a_Meeting)) {
				if(!$err) { $err.="updateMeeting() Error."; }
				$err.=" Update not allowed on $col.";
			}
		}
		if($err) {
			$this->log2file($this->s_ServiceClass,"$err\n");
			if (defined("THROW_ERRORS")) { throw new Exception($err); }
			return $b_Out;
		}

		// $trace = "updateMeeting() debug. ". print_r($a_Meeting, true);
		// $this->log2file($this->s_ServiceClass, $trace, $this->debugLog);

		// eventually, we need to validate the updates
		// $this->chkMeetingConfig($a_Meeting);
		// bug 3055 - Keep EdgeCounts tidy.
		if (isset($a_Meeting['Meet_EnableJoin'])) {
			if (intval($a_Meeting['Meet_EnableJoin'])<1) {
				$qqq="delete from EdgeCounts where Meet_ID='".$a_Meeting['Meet_ID']."'";
				mysql_query($qqq, $db);
			}
		}

		// if all ok, build query
		// timestamp of modification
		$s_UpdString="Meet_ModifyDateTime=utc_timestamp()";
		$s_WhereClause="where Meet_ID=".$a_Meeting['Meet_ID'];
		// Handle scheduled date/time
		if (isset($a_Meeting['Meet_ScheduledDateTime'])) {
			if (isset($a_Meeting['TzOffset']) && $a_Meeting['TzOffset']) {
				$tzoffset=$a_Meeting['TzOffset'];
				// need to convert the input to UTC
				// Meet_ScheduledDateTime format=YYYY-MM-DD hh:mm:ss
				list($sdt,$stm)=explode(" ",$a_Meeting['Meet_ScheduledDateTime']);
				list($yy,$mo,$dy)=explode("-",$sdt);
				list($hh,$mm,$ss)=explode(":",$stm);
				$ltm=gmmktime($hh,$mm,$ss,$mo,$dy,$yy) + ($tzoffset*60);
				$utm=gmdate("Y-m-d H:i:s",$ltm);
				$s_UpdString.=",Meet_ScheduledDateTime='$utm',Meet_ExpireDateTime=date_add('$utm', INTERVAL 6 MONTH)";
			} else {
				$s_UpdString.=",Meet_ScheduledDateTime='".$a_Meeting['Meet_ScheduledDateTime']."'";
			}
		}
		// Handle end date/time
		if (isset($a_Meeting['Meet_EndDateTime'])) {
			if (isset($a_Meeting['TzOffset']) && $a_Meeting['TzOffset']) {
				$tzoffset=$a_Meeting['TzOffset'];
				// need to convert the input to UTC
				// Meet_EndDateTime format=YYYY-MM-DD hh:mm:ss
				list($sdt,$stm)=explode(" ",$a_Meeting['Meet_EndDateTime']);
				list($yy,$mo,$dy)=explode("-",$sdt);
				list($hh,$mm,$ss)=explode(":",$stm);
				$ltm=gmmktime($hh,$mm,$ss,$mo,$dy,$yy) + ($tzoffset*60);
				$utm=gmdate("Y-m-d H:i:s",$ltm);
				$s_UpdString.=",Meet_EndDateTime='$utm'";
			} else {
				$s_UpdString.=",Meet_EndDateTime='".$a_Meeting['Meet_EndDateTime']."'";
			}
		}
		// handle change in Logo_ID
		if (isset($a_Meeting['Logo_ID'])) {
		}

		// handle change in StreamManagers (Meet_DepoHostInvites)
		if (isset($a_Meeting['Meet_DepoHostInvites'])) {
			$this->currentStreamManagers($a_Meeting['Meet_ID'], $a_Meeting['Meet_DepoHostInvites']);
		}

		// handle teleconferencing change - if numbers are being set,
		// the current ones must be released.
		$tcProp=array("Meet_CallInNumber","Meet_ModeratorCode","Meet_AttendeeCode");
		$tcFlag=0;
		foreach($tcProp as $trigger) {
			if (array_key_exists($trigger, $a_Meeting)) { $tcFlag=1; }
		}
		if ($tcFlag>0) {
			// if we sense a change in Teleconferencing, call changeTC()
			// to release codes.
			$trace="updateMeeting() sensed TC change.";
			$this->log2file($this->s_ServiceClass, $trace, $this->debugLog);
			$this->changeTC($a_Meeting);
		}

		foreach ($a_Meeting as $key=>$val) {
			// skip protected columns, generate sql for update-able ones.
			if ($key=="Meet_ID") { continue; }
			if ($key=="Logo_ID") { continue; } // no such column, Meet_RegPageStyle updated.
			if ($key=="TzOffset") { continue; } // no such column, only for calculations
			if ($key=="Meet_ScheduledDateTime") { continue; } // already taken care of
			if ($key=="Meet_EndDateTime") { continue; } // already taken care of
			else {
				$s_UpdString.=",";
				$s_Tmp=mysql_real_escape_string($val,$db);
				$s_UpdString.="$key='$s_Tmp'";
			}
		}

		$s_Sql="update Meetings set $s_UpdString $s_WhereClause";
		// Execute!
		if (mysql_query($s_Sql,$this->dbh)) {
			$this->log2file($this->s_ServiceClass,"updateMeeting() successful. SQL: $s_Sql\n");
			$sql="select * from Meetings where Meet_ID=".$a_Meeting['Meet_ID'];
			$res=mysql_query($sql,$this->dbh);
			if ($res) {
				$row=mysql_fetch_assoc($res);
				mysql_free_result($res);
			}
			$b_Out=$row;
		} else {
			$out="updateMeeting() failed. SQL: $s_Sql\n".mysql_error($db);
			$this->log2file($this->s_ServiceClass,$out);
		}
		return $b_Out;
	}

	/**
	* Update the logo for the registration page.
	* Temporary method of updating registration page. More comprehensive support
	* is intended in future versions.
	*
	* @param int Account ID.
	* @param int AccountLogin ID.
	* @param int Meeting ID.
	* @param int Preferred Logo ID.
	* @return Boolean
	*/
	function updateMeetingRegLogo($acct, $alid, $mtgid, $logo) {
		$pgStyle=$this->getRegPageStyle($acct, $alid, $logo);
		if ($pgStyle<1) {
			$errs="Database error. Could not set registration style.";
			$this->log2file($this->s_ServiceClass,"createMeeting() $errs\n");
			if (defined("THROW_ERRORS")) { throw new Exception($errs); }
			// currently not a fatal error, but uncomment the next 2 if it should be.
			// $b_Out.=". $errs";
			// return $out;
		}
		$db=$this->dbh;
		$sql="update Meetings set Meet_RegStyleID=$pgStyle where Meet_ID='$mtgid'";
		$ok=mysql_query($sql, $db);
		if (!$ok) {
			$err="updateMeetingRegLogo() failed. ".mysql_error();
			$this->log2file($this->s_ServiceClass, $err);
		}
		return $ok;
	}

	/**
	* Get a meeting list.
	* This may supercede getMeetingList(). It is almost the same in operation, but
	* returns a condensed version of the results. Each object in the array has the
	* properties: <i>Meet_ID</i>, <i>Meet_Name</i>, and <i>Meet_ScheduledDateTime</i>.
	* If b_Join evaluates to false, inactive meetings are included in the results.
	* @param int Account Login ID.
	* @param Boolean Active/Inactive filter.
	* @return Array List of Meeting identifiers.
	*/
	function getMeetingQuickList($n_ALID,$b_Join) {
		$db=$this->dbx;
		$out=array();
		// bug 3245 - don't list sampledepo
		$sql="select Meet_ID,Meet_Name,Meet_ScheduledDateTime,Meet_TollConferenceMode, ".
		"Meet_CallInNumber, Meet_AttendeeCode, Meet_Password, Meet_RequireRegistration, ".
		"Meet_EnableDepo, Meet_EnableAutoAccept, Meet_ScheduledTimeZone from Meetings where ".
		"AL_ID=$n_ALID && Meet_IsDemo=0";
		if ($b_Join) { $sql.=" && Meet_EnableJoin=1"; }
		$sql.=" order by Meet_Name";
		$res=mysql_query($sql,$db);
		if ($res) {
			while (($row=mysql_fetch_assoc($res))!=false) { array_push($out,$row); }
			mysql_free_result($res);
		}
		if(defined("THROW_ERRORS") && count($out)<1) {
			throw new Exception("No results.");
		}
		return $out;
	}

	/**
	* Get list of meetings.
	* Requires Account ID, AccountLogin ID and JoinEnabled flag.
	* If JoinEnabled flag is non-zero, results are limited to records where Meet_EnableJoin is 1.
	* Returns array of objects - each object being a complete meeting record.
	* @param int Valid account ID
	* @param int Valid account-login ID.
	* @param Boolean
	* @return Array
	*/
	function getMeetingList($n_AcctID, $n_ALID, $b_JoinFlag) {
		$db=$this->dbx;
		$a_Out=array();
		// bug 3245 - don't list sampledepo
		$s_Sql="select a.*, if(Logo_ID, Logo_ID, 0) as Logo_ID, Acc_EnablePrivateBranded ".
		"from Accounts c, Meetings a left join RegPageStyle ".
		"on Meet_RegStyleID=RPS_ID where a.Acc_ID=c.Acc_ID && a.Acc_ID='$n_AcctID' ".
		"&& a.AL_ID='$n_ALID' && Meet_IsDemo=0";
		if ($b_JoinFlag) { $s_Sql.=" and Meet_EnableJoin=1"; }
		$s_Sql.=" order by Meet_Name";
		$res=mysql_query($s_Sql,$db);
		if ($res) {
			while (($row=mysql_fetch_assoc($res))!=false) {
				$isPb=$row['Acc_EnablePrivateBranded'];
				unset($row['Acc_EnablePrivateBranded']);
				if($row['Meet_RegStyleID']<1) {
					$row['Logo_ID']=($isPb)?0:1;
				}
				$a_Out[]=$row;
			}

			mysql_free_result($res);
		} else {
			$this->log2file($this->s_ServiceClass,"getMeetingList() error. SQL: $s_Sql\n".mysql_error($db));
		}
		return $a_Out;
	}

	/**
	* Retrieve a block of meetings for a specific account.
	*
	* @param int Account ID.
	* @param int Login ID. Can be 0 if the user role is 'admin'.
	* @param int Active meeting filter. Set to zero to include inactive meetings.
	* @param String Meeting type filter.
	* @param int Page number. Which page to retrieve.
	* @param Boolean Indicates whether an entire Meeting record should be returned. If false, then each result will
	* be limited to the following columns only: Acc_ID, AL_ID, Meet_ID, Meet_Name and Meet_ScheduledDateTime.
	* @return Array List of meeting objects.
	*/
	function getSmartMeetingList($n_Acct, $n_ALID, $b_Active, $s_MType, $n_Page=1, $b_FullRec=true) {
		$db = $this->dbh;
		$out = array();
		$pgsz = 100;
		$fmstype = strtolower($s_MType);
		// only admin authenticated users can pass 0 for al_id
		if ($_SESSION['role'] != 'admin' || (isset($n_ALID) && $n_ALID > 0)) {
			$alid = mysql_escape_string($n_ALID);
			$login = "&& a.AL_ID = '$alid'";
		} else { $login = ""; }

		switch($fmstype) {
			case "meet":
				$mtype = "&& Meet_EnableDepo=0";
				break;
			case "depo":
				$mtype = "&& Meet_EnableDepo=1";
				break;
			default:
				$mtype = "";
		}

		// build pagination info
		$acct = mysql_escape_string($n_Acct);
		$preq = "select sum(if(Meet_EnableJoin=1,1,0)) as active, sum(if(Meet_EnableJoin=1,0,1)) ".
		"as inactive from Meetings a where Acc_ID='$acct' $login $mtype && Meet_IsDemo=0";
		$res=mysql_query($preq, $db);
		if ($res) {
			$row=mysql_fetch_assoc($res);
			$mtgactive = intval($row['active']);
			$mtginactv = intval($row['inactive']);
			mysql_free_result($res);
		}
		if ($b_Active) {
			// only count active
			$pgmax = ceil($mtgactive / $pgsz);
		} else {
			$pgmax = ceil(($mtgactive + $mtginactv) / $pgsz);
		}
		if (($mtgactive + $mtginactv)<1) { return $out; }

		$trace="getSmartMeetingList() debug. pgmax: $pgmax, [$mtgactive, $mtginactv]";
		$this->log2file($this->s_ServiceClass, $trace);

		$pgn = intval($n_Page);
		if ($pgn < 1) { $pgn = 1; }
		if ($pgn > $pgmax) { $pgn = $pgmax; }
		if ($pgn == 1) { $lmtr = "limit $pgsz"; }
		else {
			$offset = ($pgn-1) * $pgsz;
			$lmtr = sprintf("limit %d,$pgsz", $offset);
		}

		if ($b_FullRec) {
			$s_Sql = "select a.*,AL_UserName from Meetings a, AccountLogins b where a.AL_ID=b.AL_ID && a.Acc_ID='$acct'";
		} else {
			$s_Sql = "select Acc_ID, AL_ID, Meet_ID, Meet_Name, Meet_ScheduledDateTime, Meet_ScheduledTimeZone, Meet_EndDateTime from Meetings a where Acc_ID='$acct'";
		}

		if ($b_Active) { $s_Sql.=" && Meet_EnableJoin=1"; }
		$s_Sql .= " && Meet_IsDemo=0 $login $mtype $lmtr";
		$res=mysql_query($s_Sql,$db);
		if ($res) {
			$resno = 0;
			while(($row=mysql_fetch_assoc($res))!=false) {
				$resno++;
				$row['PageInfo'] = "$resno:$pgn:$pgmax";
				array_push($out,$row);
			}
			mysql_free_result($res);
		} else {
			$errs=mysql_error($db);
			$this->log2file($this->s_ServiceClass,"getSmartMeetingList() failed. SQL: $s_Sql\n$errs\n");
		}
		return $out;
	}

	/**
	* Find meetings by name.
	*
	* @param int Account ID.
	* @param int Login ID. Can be 0 if the user role is 'admin'.
	* @param String Keyword/Search term.
	* @param int 1=only active, 0=include inactive.
	* @param String Meeting type.
	* @param Boolean Flag which if true, will search both Meet_Name and AL_UserName fields. If false, will only search Meet_Name field.
	* @param Boolean Indicates whether an entire Meeting record should be returned. If false, then each result will
	* be limited to the following columns only: Acc_ID, AL_ID, Meet_ID, Meet_Name and Meet_ScheduledDateTime.
	*/
	function findMeetingsByName($n_Acct, $n_ALID, $s_Search, $b_Active, $s_MType, $b_SearchLogins=true, $b_FullRec=true) {
		$db = $this->dbh;
		$out = array();
		$pgsz = 100;
		$kw = mysql_escape_string($s_Search);
		$fmstype = strtolower($s_MType);
		switch($fmstype) {
			case "meet":
				$mtype = "&& Meet_EnableDepo=0";
				break;
			case "depo":
				$mtype = "&& Meet_EnableDepo=1";
				break;
			default:
				$mtype = "";
		}
		$srch ="&& (Meet_Name like '%$kw%'";
		if ($b_SearchLogins) $srch .= " || AL_UserName like '%$kw%'";
		$srch .= ")";
		$acct = mysql_escape_string($n_Acct);
		// only admin authenticated users can pass 0 for al_id
		if ($_SESSION['role'] != 'admin' || (isset($n_ALID) && $n_ALID > 0)) {
			$alid = mysql_escape_string($n_ALID);
			$login = "&& a.AL_ID = '$alid'";
		} else { $login = ""; }

		$lmtr = "limit $pgsz";
		if ($b_FullRec) {
			$s_Sql = "select a.*,AL_UserName from Meetings a, AccountLogins b where a.AL_ID=b.AL_ID && a.Acc_ID='$acct'";
		} else {
			$s_Sql = "select Acc_ID, AL_ID, Meet_ID, Meet_Name, Meet_ScheduledDateTime, Meet_ScheduledTimeZone, Meet_EndDateTime from Meetings a where Acc_ID='$acct'";
		}
		if ($b_Active) { $s_Sql.=" && Meet_EnableJoin=1"; }
		$s_Sql .= " && Meet_IsDemo=0 $srch $login $mtype $lmtr";

		$trace = "findMeetingsByName() debug. SQL: $s_Sql";
		$this->log2file($this->s_ServiceClass, $trace);

		$res=mysql_query($s_Sql,$db);
		if ($res) {
			$resno = 0;
			while(($row=mysql_fetch_assoc($res))!=false) {
				$resno++;
				$row['PageInfo'] = "$resno:$pgn:$pgmax";
				array_push($out,$row);
			}
			mysql_free_result($res);
		} else {
			$errs=mysql_error($db);
			$this->log2file($this->s_ServiceClass,"findMeetingsByName() failed. $errs\n");
		}
		return $out;
	}

	function getMeetingRecordByID($n_ALID, $n_MeetID, $b_Active=false) {
		$db=$this->dbx;
		$hostID = mysql_escape_string($n_ALID);
		$meetID = mysql_escape_string($n_MeetID);
		$out=array();
		$sql="select * from Meetings where AL_ID='$hostID' && Meet_ID='$meetID'";
		if ($b_Active) { $sql .= " && Meet_EnableJoin=1"; }
		$res=mysql_query($sql,$db);
		if ($res) {
			while (($row=mysql_fetch_assoc($res))!=false) {
				array_push($out, $row);
			}
			mysql_free_result($res);
		} else {
			$this->log2file($this->s_ServiceClass,"getMeetingByID() error. SQL: $s_Sql\n".mysql_error($db));
		}
		return $out;
	}

	function updateFMS($n_Meet, $s_FMS) {
		$db=$this->dbh;
		$acct=$_SESSION['acct'];
		$fms=mysql_escape_string($s_FMS);
		$sql="update Meetings set Meet_MSDomain='$fms' where Meet_ID='$n_Meet' && Acc_ID='$acct'";
		$res=mysql_query($sql,$db);
		if (!$res) {
			$err="updateFMS() - failed. SQL: $sql\n".mysql_error();
			$this->log2file($this->s_ServiceClass,$err);
		}
		return $res;
	}

	/*
	* Get a specific meeting property.
	* When it does not make sense to retrieve a whole record, call this
	* method to get specific properties. Provide an object with the signature:
	* <code>
	* meetID:int - Valid meeting ID
	* propList:Array - List of properties to return.
	* </code>
	*
	* The returned object should have the properties specified in propList with respective
	* values. If one of the fields in propList is not valid, or the object is incomplete,
	* an empty object is returned.
	* @param Object Defines the desired meeting properties.
	* @return Object Partial meeting record that has the specified properties.
	function getMeetingProperties($o_props) {
		$db=$this->dbh;
		$out=array();
		if (!isset($o_props['meetID'])) { return $out; }
		$cols="";
		foreach($o_props['propList'] as $ele) {
			if ($cols) { $cols.=","; }
			$cols.=$ele;
		}
		$sql="select $cols from Meetings where Meet_ID=".$o_props['meetID'];
		$res=mysql_query($sql,$db);
		if ($res) {
			$out=mysql_fetch_assoc($res);
			mysql_free_result($res);
		}
		return $out;
	}
	*/

	/**
	* Get domain's entire list of meetings.
	* Requires Account ID.
	* Returns array of objects - each object being a complete meeting record.
	* Only 'joinable' meetings are returned.
	* @param int Valid account ID
	* @return Array
	*/
	function getDomainMeetingList($n_AcctID) {
		$db=$this->dbx;
		$a_Out=array();
		$x=0;
		$s_Sql="select * from Meetings where Acc_ID=$n_AcctID and Meet_EnableJoin=1 order by Meet_Name";
		$res=mysql_query($s_Sql,$db);
		if ($res) {
			while (($row=mysql_fetch_assoc($res))!=false) {
				$a_Out[$x]=$row;
				$x++;
			}
			mysql_free_result($res);
		} else {
			$err="getDomainMeetingList() error. SQL: $s_Sql\n".mysql_error($db);
			$this->log2file($this->s_ServiceClass,$err);
		}
		return $a_Out;
	}

	/**
	* Get the number of seats remaining for a specific account.
	* Requires Account ID.
	* Returns the current number of available seats. The number is calculated by summing
	* the Meet_CurrentUsers values of active meetings that belong to an Account ID, then
	* subtracting that from the Acc_Seats value in the Accounts record.
	* @param int
	* @return int
	*/
	function getAccountSeatsRemaining($n_AcctID) {
		$db=$this->dbx;
		$n_Out=0;
		$s_Sql="select Acc_Seats-sum(Meet_CurrentUsers) from Accounts a, Meetings b".
		" where a.Acc_ID=b.Acc_ID && a.Acc_ID=$n_AcctID";
		$res=mysql_query($s_Sql,$db);
		if ($res) {
			list($n_Out)=mysql_fetch_array($res);
			mysql_free_result($res);
		} else {
			$errstr="getAcctSeatsRemaining() error. SQL: $s_Sql\n".mysql_error($db);
			$this->log2file($this->s_ServiceClass,$errstr);
		}
		return $n_Out;
	}

	/**
	* Get a specific host property.
	* When it does not make sense to retrieve a whole record, call this
	* method to get specific properties. Provide an object with the signature:
	* <code>
	* alid:int - Valid Account Login ID.
	* propList:Array - List of properties to return.
	* </code>
	*
	* The returned object should have the properties specified in propList with respective
	* values. If one of the fields in propList is not valid, or the object is incomplete,
	* an empty object is returned.
	* @param Object Defines which properties to retrieve.
	* @return Object Partial account login record that has the specified properties.
	*/
	function getHostProperties($o_props) {
		$db=$this->dbx;
		$out=array();
		$vp=0;
		$pic=0;

		if (!isset($o_props['alid'])) { return $out; }
		$alid=$o_props['alid'];
		if (intval($alid)<1) {
			$err="Invalid login id: $alid";
			$this->log2file($this->s_ServiceClass,$err);
			return false;
		}
		// don't return the meeting password, return a scrambled version
		$cols="";
		$acFlag=0;
		foreach ($o_props['propList'] as $prop) {
			if ($prop == "VideoProfiles") { $vp=1; continue; }
			if ($prop == "Snapshot") { $pic=1; continue; }
			if (substr($prop,0,3) == "Acc") { $acFlag=1; }
			if ($prop == "Acc_MSApplication") { $prop = "b.Acc_MSApplication"; }
			if ($prop == "Acc_MSDomain") { $prop = "b.Acc_MSDomain"; }
			if ($cols) { $cols.=","; }
			if ($prop == "AL_Password") { $cols.="(if(AL_Password='',0,1)) as AL_Password"; }
			else { $cols.=$prop; }
		}

		if ($acFlag) {
			$sql="select $cols from AccountLogins a, AccountSettingProfiles b, Accounts c ".
			"where a.Asp_ID=b.Asp_ID && c.Acc_ID=b.Acc_ID && AL_ID='$alid'";
		} else {
			$sql="select $cols from AccountLogins where AL_ID='$alid'";
		}
		$res=mysql_query($sql,$db);
		if ($res) {
			$out=mysql_fetch_assoc($res);
			mysql_free_result($res);

			if ($vp) {
				$out['VideoProfiles']=$this->getVideoProfiles($alid);
			}

			if ($pic) {
				$out['Snapshot'] = $this->getSnapshot($alid);
			}
		}
		else {
			$err="getHostProperties() error. SQL: $sql\n".mysql_error();
			$this->log2file($this->s_ServiceClass,$err);
		}

		// Check DefaultUserPerms if nec.
		if (isset($o_props['AL_DefaultUserPerms'])) {
			$sql="select Acc_DefaultUserPerms from Accounts a, AccountLogins b where ".
			"a.Acc_ID=b.Acc_ID && AL_ID=$alid";
			$res=mysql_query($sql,$db);
			$acp=$out['AL_DefaultUserPerms'];
			if ($res) {
				list($acp)=mysql_fetch_array($res);
				mysql_free_result($res);
			}
			$tmp=$this->compareTerms($out['AL_DefaultUserPerms'],$acp);
			$out['AL_DefaultUserPerms']=$tmp;
		}
		/* added 7 Dec 2012. Dropbox auth depends on email, and the livedepo swf
		is not aware of a stream manager's email - only the host email. Since the
		Dropbox authorization is bound to an email, multiple depositions may
		suffer from *mangled* dropboxes if vhosts/stream managers use the same
		host email.
		*/
		if (isset($o_props['dskey'])) {
			$dskey=mysql_escape_string($o_props['dskey']);
			$sql="select Invite_Email from InvitationList where Invite_ClientKey='$dskey'";
			$res=mysql_query($sql,$db);
			if ($res) {
				$row=mysql_fetch_array($res);
				$out['Invite_Email']=$row[0];
				mysql_free_result($res);
			}
		}

		return $out;
	}

	/**
	* Ban/unban an IP address (domain specific).
	* Use this function to ban or unban an IP from your domain.
	* @param String IP address.
	* @param String Domain.
	* @param int Banned status. 1=banned, 0=unbanned.
	* @return Boolean
	*/
	function setBanned($s_IP,$s_Domain,$n_Ban) {
		$db=$this->dbh;
		$dom=mysql_escape_string($s_Domain);
		if ($n_Ban>0) {
			$sql="insert into BanList (Acc_ID,BL_IP,BL_CreateDateTime) ".
			"select Acc_ID,'$s_IP',utc_timestamp() from Accounts where Acc_Domain='$dom'";
		} else {
			$sql="delete a.* from BanList a, Accounts b where ".
			"a.Acc_ID=b.Acc_ID && BL_IP='$s_IP' && Acc_Domain='$dom'";
		}
		// $this->log2file($this->s_ServiceClass,"setBanned() debug. SQL: $sql");
		$ok=mysql_query($sql,$db);
		if (!$ok) {
			$errstr="setBanned() error. SQL: $sql\n".mysql_error($db);
			$this->log2file($this->s_ServiceClass,$errstr);
		}
		return $ok;
	}

	/**
	* Get list of banned IPs.
	* @param int Account ID.
	* @return Array List of banned IPs for a domain.
	*/
	function getBanList($n_Acct) {
		$db=$this->dbx;
		$out=array();
		$x=0;
		$sql="select BL_IP, BL_CreateDateTime from BanList where Acc_ID=$n_Acct";
		$res=mysql_query($sql,$db);
		if ($res) {
			while(($row=mysql_fetch_assoc($res))!=false) {
				$out[$x]=$row;
				$x++;
			}
			mysql_free_result($res);
		} else {
			$errstr="getBanList() error. SQL: $s_Sql\n".mysql_error($db);
			$this->log2file($this->s_ServiceClass,$errstr);
		}
		return $out;
	}

	/**
	* Get an invitation template.
	* If there is no template associated to an account login, a default template is returned.
	* @param int Account Login ID.
	* @return String Invitation template.
	*/
	function getInvTemplate($n_ALID) {
		$db=$this->dbx;
		$out="";
		$sql="select Ivt_Body from InvTemplates where AL_ID=$n_ALID && Ivt_Default=1";
		$res=mysql_query($sql,$db);
		if ($res && mysql_num_rows($res)) {
			list($out)=mysql_fetch_array($res);
			mysql_free_result($res);
		} else {
			$sql="select Ivt_Body from InvTemplates where Ivt_ID=1";
			$res=mysql_query($sql,$db);
			if ($res) {
				list($out)=mysql_fetch_array($res);
				mysql_free_result($res);
			}
		}
		return $out;
	}

	/**
	* Get deposition token.
	* In the event that a user joins through the host/admin pages, we need to
	* be able to provide a usable token without having to send an email.
	*
	* First parameter should be an ID. If admin, set this to the account ID,
	* otherwise set this to account login ID. The second parameter should be the
	* meeting ID.
	*
	* Note that this method is dependent on an authorized session, and therefore
	* cannot be effectively used by an API, or other page that does not maintain
	* the php session variables.
	*
	* @param int ID. Depending on auth level, this can be Acc_ID, or AL_ID
	* @param int Meeting ID.
	* @return String Unique string, or "Failed".
	*/
	function getKey($id, $mtgID) {
		$db=$this->dbh;
		$out="Failed";
		// check auth
/*
		$trace="getKey() debug, checking vars.";
		$this->log2file($this->s_ServiceClass, $trace);
*/
		if (!isset($_SESSION['role'])) { return $out; }
		$acct=$_SESSION['acct'];
		$role=$_SESSION['role'];
		$domain=$_SESSION['domain'];
		$link="";
/*
		$trace="getKey() debug, checking auth. $id vs $acct - $role";
		$this->log2file($this->s_ServiceClass, $trace);
*/
		if ($role=='admin' && $id!=$acct) { return $out; }

		// check for existing key
/*
		$trace="getKey() debug, checking for key.";
		$this->log2file($this->s_ServiceClass, $trace);
*/
		$asid="$role-$id-$mtgID";
		$dsi="";
		$sql="select Invite_ClientKey from InvitationList where Invite_ID='$asid' && Meet_ID='$mtgID'";
		$res=mysql_query($sql,$db);
		if ($res) {
			list($dsi)=mysql_fetch_array($res);
			mysql_free_result($res);
		}
		// create if necessary
		if (!$dsi) {
			// $dsi=chr(rand(103,122)).dechex($mtgID).$this->sglAuthToken();
			$dsi=$this->sglAuthToken($mtgID);
			$sql="insert into InvitationList (Invite_ID, Meet_ID, Invite_ClientKey,Invite_Domain, ".
			"Invite_URL) values ('$asid','$mtgID','$dsi','$domain','$link')";
			if (!mysql_query($sql,$db)) {
				$err="getKey() error. SQL: $sql\n".mysql_error($db);
				$this->log2file($this->s_ServiceClass,$err);
			} else { $out=$dsi; }
		} else { $out=$dsi; }
		return $out;
	}

	/**
	* Create a single-use link to a host session.
	* This method was meant to be used via the API, however it can also be used in an
	* amfphp context as well.
	*
	* The Account ID is mostly a security measure and is not used if this method is
	* used in an amfphp context; the value gets inherited from the session.
	* The Account Login ID should be a valid sub account of the Account ID.
	* The meeting ID can be zero. This will cause the link to redirect to the Host
	* page rather than directly into a meeting.
	* If the 'uname' parameter is left empty, it will default
	* to the Account Login's username.
	*
	* As the name implies, the links are single-use and cannot be reused.
	*
	* @param int Account ID.
	* @param int Account Login ID.
	* @param int Meeting ID. Valid meeting ID, but zero can be used.
	* @param String Human-readable name.
	* @param int Can be 1 or 0. Value of 1 specifies limited (vhost).
	* @return String Session URL, or error message.
	*/
	function oneTimeHost($accID, $alID, $mtgID=0, $uname="", $vhost=0) {
		$db=$this->dbh;
		$link="";
		$acct=(isset($_SESSION['acct']))?$_SESSION['acct']:$accID;
		$entry=1;
		/* updated 8 Aug 2012. Virgil S. Palitang.
		Removed the database constraint requiring a valid meeting ID. Now
		we just make sure vhosts do not access the host page.
		*/
		mysql_query("set names utf8", $db);
		if ($mtgID) {
			$qqq="select Acc_Domain, Meet_HostUserName as UserName from Meetings a, Accounts b ".
			"where a.Acc_ID=b.Acc_ID && Meet_ID=$mtgID && a.Acc_ID=$acct";
		} else {
			$qqq="select Acc_Domain, AL_UserName as UserName from AccountLogins a, Accounts b ".
			"where a.Acc_ID=b.Acc_ID && a.Acc_ID='$acct' && AL_ID='$alID'";
			if ($vhost) {
				$err="Failed. Invalid request. Meeting ID not specified.";
				$this->log2file($this->s_ServiceClass,"oneTimeHost() $err");
				if (defined("THROW_ERRORS")) { throw new Exception($err); }
				return $err;
			}
			$entry=0;
		}
		$res=mysql_query($qqq,$db);
		if ($res) {
			$row=mysql_fetch_assoc($res);
			mysql_free_result($res);
		}

		if (!isset($row['Acc_Domain'])) {
			$err="Failed. Invalid arguments. Acct: $acct, ALID: $alID, MeetID: $mtgID";
			$this->log2file($this->s_ServiceClass,"oneTimeHost() $err");
			if (defined("THROW_ERRRORS")) { throw new Exception($err); }
			return $err;
		}
		if ($uname=='' || $mtgID<1) { $usr=mysql_escape_string($row['UserName']); }
		else { $usr=mysql_escape_string($uname); }
		$domain=$row['Acc_Domain'];

		// new validation code
		$asid=$this->createGUID();
		// $vc=$this->makeVcode($asid);
		$htype=($vhost>0)?"vhost":"host";
		$sql="insert into APISessions (AS_ID, Meet_ID, AS_Type, AS_UserName, AS_EnterMeeting,".
		" AS_CreateDateTime, AS_Account) values ('$asid','$mtgID','$htype','$usr', '$entry', ".
		"utc_timestamp(), '$acct')";
		// $trace="oneTimeHost() debug. SQL: $sql\n";
		// $this->log2file($this->s_ServiceClass, $trace, "/var/log/httpd/amfphp_debug_log");

		if (!mysql_query($sql,$db)) {
			$errs="oneTimeHost() SQL:\n$sql".mysql_error();
			$this->log2file($this->s_ServiceClass,$errs);
			if (defined("THROW_ERRRORS")) { throw new Exception($errs); }
			$link="Failed. Error creating session.";
		} else {
			$link="http://$domain/auth.php?session=$asid";
		}
		return $link;
	}

	/**
	* Helper for developing 3rd-party applications.
	* Mostly for API usage, since amfphp will already have an authorized
	* session. This method was developed as part of a solution to
	* address issues with 3rd-party cookies. Some browsers are set to
	* reject 3rd-party cookies, and with external companies using iframes
	* the problem becomes unavoidable.
	*
	* The objective is to have the web application post data to the AMFEndpoint
	* where a cookie is set, then the client may proceed into the meeting.
	* Solution taken from:
	* http://stackoverflow.com/questions/4701922/how-does-facebook-set-cross-domain-cookies-for-iframes-on-canvas-pages
	*
	* Returned object follows the structure:<code>
	* {
	* endpoint:String - URL where the client starts the entry process.
	* txid:String - Unique identifier.
	* }
	* </code>
	*
	* The transaction ID (txid) is sent to the endpoint along with the preferred
	* username, and the client is redirected to the appropriate page. Please note
	* that transaction IDs (txid) are single-use and expire when the endpoint
	* receives them.
	*
	* 'host-page' entry is supported. By setting the meeting ID parameter to zero (0),
	* the page will go the respective host page instead of a meeting. This is only
	* available where the role is set to 'host'.
	*
	* @param int AccountLogin ID
	* @param int Meeting ID
	* @param string Role/auth level. Currently only 'host' and 'guest' are supported.
	* @return Object See description.
	*/
	function createP3PData($alid,$meetID,$type) {
		$db=$this->dbh;
		$out=array();
		$ent=1;
/*
		if ($type=='host') {
			if ($meetID<1) { $meetID=$this->getInactiveMtg($alid); $ent=0; }
		} else {
*/
			if ($type!='host' && $meetID<1) {
				$err="createP3PData() - cannot auth $type to meetingID $meetID";
				$this->log2file($this->s_ServiceClass,$err);
				if (defined("THROW_ERRORS")) { throw new Exception($err); }
				return $out;
			}
//      }
		/* get endpoint and username */
		$sql="select a.Acc_ID, Acc_AMFEndpoint, AL_UserName from Accounts a, AccountLogins b ".
		"where a.Acc_ID=b.Acc_ID && AL_ID=$alid && AL_Active=1";
		$res=mysql_query($sql,$db);
		if ($res) {
			$row=mysql_fetch_assoc($res);
			mysql_free_result($res);
		}
		if (!isset($row['Acc_AMFEndpoint'])) {
			// if no data, throw error
			$err="createP3PData() - Cannot generate data. Login $alid is disabled.";
			$this->log2file($this->s_ServiceClass,$err);
			if (defined("THROW_ERRORS")) { throw new Exception($err); }
			return $out;
		}
		$acct=$row['Acc_ID'];
		$ep=str_replace("amfphp/gateway.php","prep.php",$row['Acc_AMFEndpoint']);
		$uname=mysql_escape_string($row['AL_UserName']);
		$asid=$this->createGUID();

		/* create APISessions record */
		$sql="insert into APISessions ".
		"(AS_ID, Meet_ID, AS_Type, AS_UserName, AS_CreateDateTime, AS_EnterMeeting, ".
		"AS_Account) values ('$asid','$meetID','$type','$uname',utc_timestamp(),$ent,$acct)";
		if (!mysql_query($sql,$db)) {
			$err="createP3PData() - Cannot generate data. Login $alid is disabled.";
			$this->log2file($this->s_ServiceClass,$err);
			if (defined("THROW_ERRORS")) { throw new Exception($err); }
			return $out;
		}

		$out['endpoint']=$ep;
		$out['txid']=$asid;

		return $out;
	}

	private function getInactiveMtg($alid) {
		$db=$this->dbh;
		$out=0;
		// find a deactivated meeting
		$qqq="select Acc_Domain, Meet_ID, Meet_HostUserName from Meetings a, Accounts b where ".
		"a.Acc_ID=b.Acc_ID && Meet_EnableJoin=0 && AL_ID=$alid limit 1";
		$res=mysql_query($qqq,$db);
		if ($res) {
			$row=mysql_fetch_assoc($res);
			mysql_free_result($res);
		}
		if(isset($row['Meet_ID'])) {
			$out=$row['Meet_ID'];
		} else {
			//create deactivated meeting
			$qx="insert into Meetings (Acc_ID, AL_ID, Meet_Name, Meet_EnableJoin, Meet_Seats, ".
			"Meet_CreateDateTime, Meet_EndDateTime, Meet_EnableMeetingList, Meet_InviteComments, ".
			"Meet_HostUserName) select a.Acc_ID,'$alid','0_NoEntry',0,0,utc_timestamp(),".
			"utc_timestamp(),0,Acc_Domain,AL_UserName from Accounts a, AccountLogins b where ".
			"a.Acc_ID=b.Acc_ID && AL_ID=$alid";

			$dbg="getInactveMtg() debug. SQL: $qx";
			$this->log2file($this->s_ServiceClass,$dbg);

			if (mysql_query($qx,$db)) {
				$out=mysql_insert_id();
			} else {
				$err="getInactiveMtg() failed. ".mysql_error();
				$this->log2file($this->s_ServiceClass,$err);
			}
		}
		return $out;
	}

	/**
	* Deposition instruction email.
	* Send instructions to privileged deposition participants.
	* Requires an object that follows the structure:<code>
	* meetID:int - Meeting ID.
	* hostmailto:array of objects - More details below.
	* from:String - Formal name of the user issuing the invitation.
	* subject:String - The subject of the email.
	* offset:int - Host's time zone offset (minutes).
	* displayDate:String - Optional. Formatted/adjusted scheduled starting datetime.
	* sendReceiptTo:String -Optional. Where to send confirmation receipt.
	* </code>
	*
	* The <i>hostmailto</i> property has been changed from a plain string to an array
	* of objects. Each of the objects should follow the structure:<code>
	* email:String - the email address.
	* steno:int - 1 or 0, depending on whether this user can stream steno.
	* video:int - 1 or 0, depending on whether this user can stream video.
	* </code>
	*
	* @param Object Email object. See description.
	* @return Boolean
	*/
	function depoInstructions($obj) {
		$db=$this->dbh;
		$mtgID=$obj['meetID'];
		$tzo=$obj['offset'] * -1;
		$tzone=$obj['tzone'];
		$confirmAddr=(isset($obj['sendReceiptTo']))? $obj['sendReceiptTo'] : "";
		$inviteNote=(isset($obj['note']))? $obj['note'] : "";
		$tbl="";
		// $hlist=$this->emailParse($obj['hostmailto']);
		// Bug 2325 involves steno/video distinctions for each stream manager.
		// Now we have to parse the hostmailto object differently.

		// parse hostmailto object, build email list
		$hlist=array();
		$smlist=$obj['hostmailto'];
		$sminfo = array();
		foreach ($smlist as $emailObj) {
			$rcpt = $emailObj['email'];
			$tag=strpos($rcpt,'<');
			if ($tag===false) { $ivtmail=$hostUser=$rcpt; }
			else {
				$hostUser=trim(substr($rcpt,0,$tag),'\'" ');
				$ivtmail=trim(substr($rcpt,$tag),"<> ");
			}
			$hlist[]=$ivtmail;
			$sminfo[] = array(
				"user"=>$hostUser,
				"email"=>$ivtmail,
				"steno"=>$emailObj['steno'],
				"video"=>$emailObj['video'],
				"guid"=> "",
				"dskey"=>""
			);
		}

		/* smtpConfig should set class variable 'smtp' to object with structure:
		{
			EP_ID:int
			EP_SMTPHost:String
			EP_SMTPUsername:String
			EP_SMTPPassword:String
			EP_SMTPPort:int
			EP_UseSSL:int
			AL_UserName
			AL_Email
		}
		*/
		$smtpOK=$this->smtpConfig($mtgID);
		if (!$smtpOK) {
			$err="depoInstructions() failed. Could not determine mail config.";
			$this->log2file($this->s_ServiceClass, $err);
			if (defined("THROW_ERRORS")) { throw new Exception($err); }
			return false;
		}

		// get meeting info
		$meetName="";
		$meetTime="";
		$displayDate = "";
		$sql="select a.Acc_ID, Acc_Domain, Acc_AccountName, date_add(Meet_ScheduledDateTime,interval $tzo minute) ".
		"as stime, Meet_Name, Meet_DepoWitness, Meet_CallInNumber, Meet_ModeratorCode, Case_Name, AL_Email ".
		"from Meetings a, Accounts b, AccountLogins c, DepoCases d ".
		"where a.Acc_ID=b.Acc_ID && b.Acc_ID=c.Acc_ID && Meet_ID=$mtgID && a.AL_ID=c.AL_ID && ".
		"a.Case_ID=d.Case_ID";
		// $trace="depoInstructions() debug. SQL: $sql";
		// $this->log2file($this->s_ServiceClass,$trace);
		$res=mysql_query($sql,$db);
		if ($res) {
			$row=mysql_fetch_assoc($res);
			$acct=$row['Acc_ID'];
			$domain=$row['Acc_Domain'];
			$acctName=$row['Acc_AccountName'];
			$meetName=$row['Meet_Name'];
			$meetTime=$row['stime'];
			$caseName=$row['Case_Name'];
			$lebron=$row['Meet_DepoWitness'];
			$callInNum=$row['Meet_CallInNumber'];
			$modCode=$row['Meet_ModeratorCode'];
			$schEmail=$row['AL_Email']; // scheduler email
			mysql_free_result($res);
		}
		if (isset($obj["displayDate"])) {
			$meetTime=$obj["displayDate"];
			$displayDate=$meetTime;
		}

		// get existing
		$xst=array();
		$xsk=array();
		$sql="select Invite_ID, Invite_Email, Invite_ClientKey from InvitationList where ".
		"Meet_ID='$mtgID' && DCG_ID=0";
		$res=mysql_query($sql,$db);
		if ($res) {
			while(($row=mysql_fetch_assoc($res))!=false) {
				$tmpEml=$row['Invite_Email'];
				$tmpSID=$row['Invite_ID'];
				$xst[$tmpEml] = $tmpSID;
				$xsk[$tmpEml] = $row['Invite_ClientKey'];
			}
			mysql_free_result($res);
		}
		// update sminfo
		$ix=0;
		$mx=count($sminfo);
		while ($ix<$mx) {
			$eml=$sminfo[$ix]['email'];
			if (isset($xst[$eml])) {
				$sminfo[$ix]['guid'] = $xst[$eml];
				$sminfo[$ix]['dskey'] = $xsk[$eml];
			}
			$ix++;
		}

		// bugfix 2547 - don't delete, that gets handled by currentStreamManagers.
		/*
		// do deletions - emails in xst that are not in hlist get deleted
		$dlist=array_keys($xst);
		$toProc=array_diff($dlist, $hlist); // returns elements from dlist not found in hlist
		if (count($toProc)) {
			foreach($toProc as $eml) {
				$kill=$xst[$eml];
				$sql="delete from InvitationList where Invite_ID='$kill'";
				mysql_query($sql,$db);
				$sql="delete from APISessions where AS_ID='$kill'";
				mysql_query($sql,$db);
			}
		}
		*/
		// do additions
			// At this point we're just making sure all objects in sminfo have a guid. We
			// updated once before when getting existing records.
		$ix=0;
		$mx=count($sminfo);
		while($ix<$mx) {
			if (strlen($sminfo[$ix]['guid'])<5)  {
				$asid=$this->createGUID();
				$sminfo[$ix]['guid']=$asid;
			}
			// "replace" queries will be executed when we mail
			$ix++;
		}

		//$frName="";
		//if (isset($obj['from'])) { $frName=$obj['from'].' '; }
		// build email headers
		if (!is_array($hlist) || count($hlist)<1) { return; }
		// $frDomain=($isPB)?"videoconferencinginfo":"megameeting";
		$inviter='invite@'.MAILDOMAIN;
		ini_set("sendmail_from",$inviter);
		$mlstat=0;

		$bcc="";
		if (isset($obj['bcc'])) {
			$bcc="Bcc: ".$obj['bcc']."\n";
		}
		$headers  = 'MIME-Version: 1.0' . "\n".
		"From: $inviter\n$bcc".
		//"Reply-To: $schEmail\n".
		'Content-Type: text/html; charset="UTF-8"'."\n".
		'Content-Transfer-Encoding: 8bit'."\n";
		// $footer=$this->getFooter();
		$needCred=array();  // prep for credential manager
		foreach ($sminfo as $emlObj) {
			// user name
			$usr=mysql_escape_string($emlObj['user']);
			$rcpt=$emlObj['email'];

			// check for credential manager record
			// 8 Jul 2013 - Stream Managers don't need the CredentialManager email
			// if ($this->needCredMgr($ivtmail))  { $needCred[]=$ivtmail; }

			// new validation code
			$asid=$emlObj['guid'];
			$vc=$this->makeVcode($asid);
			$dsi=$emlObj['dskey'];
			// Updated 22 May 2015. Virgil S. Palitang.
			// dsi might be blank, so we'll hold off on generating link for a sec.
			// $link="http://$domain/emaillogin/?dskey=$dsi";
			$smSteno=$emlObj['steno'];
			$smVideo=$emlObj['video'];
			// build subject
			$subj="LiveLitigation Invitation - $caseName";
			// if new, create session
			if ($dsi=="") {
				$sql="insert into APISessions (AS_ID, Meet_ID, AS_Type, AS_UserName, AS_CreateDateTime, ".
				"AS_Account) values ('$asid','$mtgID','vhost','$usr',utc_timestamp(), $acct)";

				if (!mysql_query($sql,$db)) {
					$errs="depoInstructions() SQL:\n$sql".mysql_error();
					$this->log2file($this->s_ServiceClass,$errs);
				}
				// eventually, $dsi will need a real value
				$dsi=$this->sglAuthToken($mtgID);
			}
			$domainLink="http://$domain/";
			$link="http://$domain/emaillogin/?dskey=$dsi";

			// debug APISessions sql
			// $dbg="hostInstructions() APISessions debug:\n$sql";
			// $this->log2file($this->s_ServiceClass,$dbg,'/var/log/httpd/amfphp_debug_log');

			// insert matching guid in invitation list
			// We use the "replace" command so the db automatically updates.
			$sql="replace into InvitationList (Invite_ID, Meet_ID, Invite_ClientKey,Invite_Domain, ".
			"Invite_URL, Invite_Email, Invite_Name, Invite_SMSteno, Invite_SMVideo) values ".
			"('$asid','$mtgID','$dsi','$domain','$link','$rcpt','$usr', '$smSteno', '$smVideo')";
			if (!mysql_query($sql,$db)) {
				$err="depoInstructions() error. SQL: $sql\n".mysql_error($db);
				$this->log2file($this->s_ServiceClass,$err);
			}
			// create teleconferencing info if phone number exists
			$telecon="";
			if (strlen($callInNum)) {
				$telecon = "<br/><b>Call In Number / Moderator Code:</b> $callInNum / $modCode";
			}
			// Added 15 Aug 2013 - Virgil S. Palitang
			// Include a tracker graphic
			$tracker=$this->emailTrack($mtgID, $rcpt, $domain);
			$stenoLink="http://$domain/livedepo/getplugin.php";
			$msg_raw=<<<END_PHASE1
<html><body style='font-family:Arial, Helvetica, sans-serif; font-size:12px'>
<p>You have been assigned to the following LiveLitigation stream by <a href='$domainLink'>$acctName</a>.</p>
END_PHASE1;
			$msg_raw.=<<<END_PHASE2
<p><b>Case name: </b>$caseName<br/>
<b>Witness: </b>$lebron<br/>
<b>Date / Time: </b>$meetTime ($tzone)
$telecon
<br/><b>Your Key: </b>$dsi</p>
END_PHASE2;
	if (strlen($inviteNote)) {
				$msg_raw.=<<<END_PHASE3
<p><b>Notes:</b> $inviteNote</p>
END_PHASE3;
			}
			if ($smSteno) {
				$msg_raw.=<<<END_PHASE4
<b>Realtime Transcript Streaming</b>
<li><a href='$stenoLink'>Click Here to Install StenoDirectPlus</a></li>
<li>Download and install StenoDirectPlus to stream Realtime.</li><br><br>
END_PHASE4;
			}
			if ($smVideo) {
				$msg_raw.=<<<END_PHASE5
<b>Audio / Video Streaming</b>
<li><a href='$link'>Click Here to Join the LiveLitigation Stream</a></li>
<li>Click the above link to join and stream video from your PC or Mobile Device</li><br><br>
END_PHASE5;
			}
			$msg_raw.=<<<END_EMAIL
<b>How To</b>
<li><a href="http://wiki.livedeposition.com/index.php/StenoDirectPlus#Remote_Streaming_Configuration">Stream Realtime</a></li>
<li><a href="http://wiki.livedeposition.com/index.php/Streaming_Audio_and_Video">Stream Audio and Video</a></li>
<li><a href="http://wiki.livedeposition.com/index.php/Exhibits#Stamp_Exhibit">Stamp and Submit ElectronicExhibits™</a></li>
<br><br><b>Technical Support</b>
<li>Phone: 818-392-8499 ext 1</li>
<li>Email: <a href='mailto:support@livelitigation.com'>support@livelitigation.com</a></li>
$tracker
</body></html>
END_EMAIL;
			$msg1=wordwrap($msg_raw,160);

		// send host instructions
			// $status=mail($rcpt,$subj,$msg1,$headers);
			$msgSent=0;
			if ($this->smtp['EP_ID']>0) {
				$msgSent = $this->extMail($rcpt,$subj,$msg1,$headers);
				$mlstat += $msgSent;
			} else {
				if (mail($rcpt,$subj,$msg1,$headers,"-f $inviter")) { $mlstat++; $msgSent=1; }
			}
			// bug 1941 - store email for later reference
			$trace="depoInstructions() debug - $mtgID:$ivtMail";
			$this->log2file($this->s_ServiceClass, $trace, $this->debugLog);
			$sentObj=array(
				"meetID" => $mtgID,
				"email" => $rcpt,
				"success" => $msgSent,
				"key" => $dsi
			);
			if ($hostUser != $ivtMail) { $sentObj['name'] = $hostUser; }
			$this->logSentEmail($sentObj);

		// bug 2658 - Aggregated email confirmations.
			if ($confirmAddr) {
				$ix=strpos($emlObj['user'],'@');
				if ($ix===false) { $uname = $emlObj['user']; }
				else { $uname = substr($emlObj['user'], 0, $ix); }
				$tbl .= "<tr><td>Stream Managers</td><td>$uname".
				"</td><td>$rcpt</td><td>$dsi</td></tr>\n";
			}
		}
		if ($confirmAddr) {
			$mto = mysql_escape_string($confirmAddr);
			$istat=mysql_escape_string($tbl);
			$sql="insert into InvitationStats(Meet_ID, InvStat_Mailto, InvStat_Info, ".
			"InvStat_DisplayDate) values ($mtgID, '$mto', '$istat', '$displayDate')";
			mysql_query($sql, $db);
		}

		// for new credential manager records - we have a mail config set up, use it if nec.
		return ($mlstat==count($hlist));
	}

	/**
	* Send a single-instance invitation.
	* Parameter is an object that follows the structure:<code>
	* mailto:string
	* subject:string
	* body:string
	* meetID:int
	* groupID:int
	* from:string
	* </code>
	*
	* The body property should contain the string "_DEPOLINK_". This will be
	* replaced with an actual hypertext link.
	*
	* The link contains a single-instance code that is used when connecting
	* to the media server. If another client attempts to use the code at the
	* same time, the previous client is disconnected.
	*
	* @param Object
	* @return Boolean
	*/
	function depoInvitations($obj) {
		$db=$this->dbh;
		$mtgID=$obj['meetID'];
		$groupID=$obj['groupID'];
		$groupInfo = array();
		$displayDate = "";
		$iperms =array(
			"DCG_HasVOIP" => "Invite_HasVOIP",
			"DCG_HasVideo" => "Invite_HasVideo",
			"DCG_HasGroupChat" => "Invite_HasGroupChat",
			"DCG_HasPrivateChat" => "Invite_HasPrivateChat",
			"DCG_ReceiveSteno" => "Invite_ReceiveSteno",
			"DCG_ReceiveAudio" => "Invite_ReceiveAudio",
			"DCG_ReceiveVideo" => "Invite_ReceiveVideo",
			"DCG_ReceiveRaw" => "Invite_ReceiveRaw",
			"DCG_CanUpload" => "Invite_CanUpload",
			"DCG_CanDownload" => "Invite_CanDownload",
			"DCG_CanExhibit" => "Invite_CanExhibit",
			"DCG_CanDownloadSubmitted" => "Invite_CanDownloadSubmitted",
			"DCG_ExportSteno" => "Invite_ExportSteno"
		);
		if (isset($obj['displayDate'])) { $displayDate = $obj['displayDate']; }
		if (isset($obj['groupName'])) { $groupName=$obj['groupName']; }
		else { $groupName=$this->groupNameByID($groupID); }
		$hlist=$this->emailParse($obj['mailto']);
		$smtpOK=$this->smtpConfig($mtgID);  // setup SMTP
		if (!$smtpOK) {
			$err="depoInvitations() failed. Could not determine mail config.";
			$this->log2file($this->s_ServiceClass, $err);
			if (defined("THROW_ERRORS")) { throw new Exception($err); }
			return $smtpOK;
		}
		// tzo=$obj['offset'] * -1;
		// $tzone=$obj['tzone'];
		if (!is_array($hlist) || count($hlist)<1) { return; }
		$confirmAddr="";
		if (isset($obj['sendReceiptTo'])) { $confirmAddr=$obj['sendReceiptTo']; }
		$successCt=count($hlist);
		$subj="Deposition invitation";
		if (isset($obj['subject'])) { $subj=$obj['subject']; }
		// $frDomain=($isPB)?"videoconferencinginfo":"megameeting";
		$inviter='invite@'.MAILDOMAIN;
		ini_set("sendmail_from",$inviter);
		$mlstat=0;

		//$frName="";
		$sql="select Meet_Name, a.Case_ID, AL_Email, Acc_Domain, Acc_UseSSL,Acc_EnableInitCalInvite,".
		"Case_Name, Meet_DepoWitness from Meetings a, Accounts b, AccountLogins c, DepoCases d ".
		"where a.Acc_ID=b.Acc_ID && a.AL_ID=c.AL_ID && d.Case_ID=a.Case_ID && Meet_ID='$mtgID'";
		$res=mysql_query($sql,$db);
		if ($res) {
			list($meetName,$caseID,$schEmail,$domain,$ssl,$nvx,$caseName,$wtns)=mysql_fetch_array($res);
			mysql_free_result($res);
		}
		//if (!$frName) { $frName='invite';}

		// build headers
		$bcc="";
		if (isset($obj['bcc'])) {
			$bcc="Bcc: ".$obj['bcc']."\n";
		}
		$headers = 'MIME-Version: 1.0' . "\n".
		"From: $inviter\n$bcc".
		//"Reply-To: $schEmail\n".
		'Content-Type: text/html; charset="UTF-8"'."\n".
		'Content-Transfer-Encoding: 8bit'."\n";
		$needCred=array();  // prep credential manager
		foreach($hlist as $rcpt) {
			trim($rcpt);
			// User name
			$tag=strpos($rcpt,'<');
			// Bug 4102 - handle commas in the display name.
			if ($tag===false) {
				$guser=$rcpt;
				$ivtMail=$rcpt;
				$rfc2822 = $rcpt;
			} else {
				$guser=trim(substr($rcpt,0,$tag),'\'" ');
				$ivtMail=trim(substr($rcpt,$tag),'<> ');
				$rfc2822 = '"'.$guser.'"<'.$ivtMail.'>';
			}
			$usr=mysql_escape_string($guser);

			// badly formed emails get skipped.
			if (!$this->isEmailValid($ivtMail)) { continue; }

			if ($nvx && $this->needCredMgr($ivtMail)) { $needCred[]=$ivtMail; }

/* no need to do this, since it should be done in assignTokens()
			// new validation code
			// $asid=$this->sglAuthToken($asid);
			$asid=$this->createGUID();
			$dsi=chr(rand(103,122)).dechex($mtgID).$this->sglAuthToken();
			// create link
			// /guest/?meetName=(Meet_Name)&id=(Meet_ID)&case=(Case_ID)&chatID=(groupID)&name=_USER_
			$mtgNm=urlencode($meetName);
			$lduser=urlencode($guser);
			$proto=($ssl)?"https":"http";
			$depolink="$proto://$domain/guest/?id=$mtgID&meetName=$mtgNm&case=$caseID".
			"&chatID=$groupID&name=$lduser&dskey=$dsi";
			$link=str_replace('+','%20',$depolink);
			// $sglauth="$proto://$domain/sglauth.php?sid=$asid";
			// $depolink=" <a href=\"$link\" target=\"_blank\">$meetName</a>";

			$sql="insert into InvitationList (Invite_ID, Meet_ID, Invite_ClientKey, Invite_Domain, ".
			"Invite_URL) values ('$asid','$mtgID','$dsi','$domain','$link')";

			// debug APISessions sql
			// $dbg="depoInvitations() debug:\n$sql";
			// $this->log2file($this->s_ServiceClass,$dbg,'/var/log/httpd/amfphp_debug_log');

			if (!mysql_query($sql,$db)) {
				$errs="depoInvitations() SQL:\n$sql".mysql_error();
				$this->log2file($this->s_ServiceClass,$errs);
			}
*/
			// just get the invitation url
			$tkDat=$this->getTokenData($mtgID, $ivtMail);
			$link=$tkDat['Invite_URL'];
			$dsi=$tkDat['Invite_ClientKey'];

			if (!$link) {
				$asid=$this->createGUID();
				$dsi=$this->sglAuthToken($mtgID);
				// $lduser=urlencode($guser);   // forget this - the guest page will pull the name.
				$proto=($ssl)?"https":"http";
				// $depolink="$proto://$domain/guest/?name=$lduser&dskey=$dsi";
				$depolink="$proto://$domain/guest/?dskey=$dsi";
				$link=str_replace('+','%20',$depolink);

				$nuDat=array(
					"Invite_ID"=> $asid,
					"Meet_ID"=> $mtgID,
					"DCG_ID"=> $groupID,
					"Invite_ClientKey"=>$dsi,
					"Invite_Domain"=>$domain,
					"Invite_URL"=>$link,
					"Invite_Email"=>$ivtMail,
					"Invite_Name"=>$guser
				);

				// build individual permissions from assigned group
				if (!isset($groupInfo['DCG_HasVOIP'])) {
					$cols = array_keys($iperms);
					$gp = join($cols, ', ');
					$qqq = "select $gp from DepoChatGroups where DCG_ID='$groupID'";
					$res=mysql_query($qqq,$db);
					if ($res) {
						$groupInfo = mysql_fetch_assoc($res);
						mysql_free_result($res);
					}
				}
				foreach ($iperms as $dcg=>$ivt) {
					$nuDat[$ivt] = $groupInfo[$dcg];
				}

				$this->addTokenRecord($nuDat);
			}
			/*
			$trace="depoInvitations() debug. MeetID: $mtgID, mail: $ivtMail, key: $dsi";
			$this->log2file($this->s_ServiceClass,$trace);
			*/

			// bug 1941 - store email for later reference
			$trace="depoInvitations() debug - $mtgID:$ivtMail";
			$this->log2file($this->s_ServiceClass, $trace);

			// now mail
			$tracker=$this->emailTrack($mtgID, $ivtMail,$domain);
			$tmpMsg=str_replace("_DEPOLINK_",$link,$obj['body']);
			$tmpMsg=str_replace("_TOKEN_",$dsi,$tmpMsg);
			$tmpMsg.="\n$tracker";
			$tmpMsg=wordwrap($tmpMsg,160);
			$msg = "<html>\n<body style='font-family:Arial, Helvetica, sans-serif; font-size:12px'>\n".
			"$tmpMsg\n</body>\n</html>";
			$msgSent=0;
/*
			$trace="depoInvitations() debug: $headers\n$msg";
			$this->log2file($this->s_ServiceClass, $trace ,'/var/log/httpd/amfphp_debug_log');
*/

			$result=$this->updateEmailData($rfc2822);
			if($result == 1) {
				if ($this->smtp['EP_ID']>0) {
					$msgSent = $this->extMail($rfc2822,$subj,$msg,$headers);
					$mlstat += $msgSent;
					if ($msgSent) { $oksent[]=array($guser, $ivtMail, $dsi); }
				} else {
					if (mail($rfc2822,$subj,$msg,$headers,"-f $inviter")) {
						$mlstat++;
						$oksent[]=array($guser, $ivtMail, $dsi);
						$msgSent=1;
					}
				}
				$sentObj=array(
					"meetID" => $mtgID,
					"email" => $ivtMail,
					"success" => $msgSent,
					"key" => $dsi
				);
				if ($guser != $ivtMail) { $sentObj['name'] = $guser; }
				$this->logSentEmail($sentObj);
			}
		}
		if ($confirmAddr) {
			$tblOut="<tr><td>$groupName</td>";
			// build list of sent
			if (count($oksent)<1) { $tblOut.="<td colspan=\"3\" align=center>(no data)</td></tr>\n"; }
			else {
				foreach ($oksent as $row) {
					$tblSent.=$tblOut;
					foreach ($row as $ele) { $tblSent.="<td>$ele</td>"; }
					$tblSent.="</tr>\n";
				}
			}
			$istat = mysql_escape_string($tblSent);
			$hostmail = mysql_escape_string($confirmAddr);
			$ins="insert into InvitationStats (Meet_ID, InvStat_Mailto, InvStat_Info, ".
			"InvStat_DisplayDate) values ($mtgID, '$hostmail', '$istat', '$displayDate')";
			mysql_query($ins, $db);
/*
			$subj="Invitation status for '$groupName' group";
			// build list of sent
			if (count($oksent)<1) { $tblSent="<tr><td width=100% align=center>(no data)</td></tr>\n"; }
			else {
				$tblSent="<tr><th>Name</th><th>Email</th><th>Key</th></tr>\n";
				foreach ($oksent as $row) {
					$tblSent.="<tr>";
					foreach ($row as $ele) { $tblSent.="<td>$ele</td>"; }
					$tblSent.="</tr>\n";
				}
			}

			$confirmMsg=<<<END_STATUSMAIL
<html>
<head>
<title> $subj </title>
</head>
<body>
Your invitations were sent successfully!<br/><br/>
<b>Case Name:</b> $caseName<br/>
<b>Witness:</b> $wtns<br/>
<b>Chat Group:</b> $groupName<br/><br/>
<b>Invitations sent:</b>
<table width="100%" border=1 cellspacing=0 cellpadding=0>
$tblSent
</table>
</body></html>
END_STATUSMAIL;

			// send status email
			$headers = 'MIME-Version: 1.0' . "\n".
			"From: $inviter\n".
			//"Reply-To: $schEmail\n".
			'Content-Type: text/html; charset="UTF-8"'."\n".
			'Content-Transfer-Encoding: 8bit'."\n";
			if ($this->smtp['EP_ID']>0) {
				$this->extMail($confirmAddr,$subj,$confirmMsg,$headers);
			} else {
				mail($confirmAddr,$subj,$confirmMsg,$headers,"-f $inviter");
			}
*/
		}
		// for new credential manager records - we have a mail config set up, use it if nec.
		if (count($needCred)) {
			$credHelper=new CredentialMgrHelper($db);
			// $inviter='noreply@'.MAILDOMAIN; // already set
			$subj="Welcome to LiveLitigation";
			// build a message
			$hdrs='MIME-Version: 1.0' . "\n".
				"From: $inviter\n".
				'Content-Type: text/html; charset="UTF-8"'."\n".
				'Content-Transfer-Encoding: 8bit'."\n";

			foreach ($needCred as $rcpt) {
				$body=$credHelper->initCredMgr($rcpt);
				// send it
				$result=$this->updateEmailData($rcpt);
				if($result==1) {
					if ($this->smtp['EP_ID']>0) { $this->extMail($rcpt,$subj,$body,$hdrs); }
					else { mail($rcpt,$subj,$body,$hdrs,"-r $inviter"); }
				}
			}
		}
		return ($successCt == $mlstat);
	}

	/**
	* Send confirmation of emails sent.
	* The depoInvitations() and depoInstructions() methods now log results to the
	* database. The <i>sendReceiptTo</i> property should have been specified when
	* calling those methods, and will determine where the confirmation goes.
	*
	* This method can only be called once after invitations are sent. Subsequent
	* calls without first sending more invitations will not send anything.
	*
	* @param int Meeting ID.
	* @param str Meeting Scheduled Date / Time, formatted for display.
	* @return Boolean True on success.
	*/
	function depoInvitationStats($meetID) {
		$db = $this->dbh;
		$out = false;
		$confirmAddr="";
		$sdata = "";
		$displayDate = "";
		$mtgID=intval($meetID);
		$smtpOK=$this->smtpConfig($mtgID);  // setup SMTP
		if (!$smtpOK) {
			$err="depoInvitations() failed. Could not determine mail config.";
			$this->log2file($this->s_ServiceClass, $err);
			if (defined("THROW_ERRORS")) { throw new Exception($err); }
			return $smtpOK;
		}
		$inviter='invite@'.MAILDOMAIN;
		ini_set("sendmail_from",$inviter);
		// make sure mtgID is numeric
		if (!$mtgID) {
			$trace="depoInvitationStats() debug: $meetID not int?";
			$this->log2file($this->s_ServiceClass, $trace);
			return $out;
		}

		$sql = "select Acc_Domain, Meet_Name, Meet_DepoWitness, Meet_CallInNumber, ".
		"Meet_ModeratorCode from Meetings a, Accounts b where a.Acc_ID = b.Acc_ID and Meet_ID='$mtgID'";
		$res=mysql_query($sql,$db);
		if ($res) {
			list($domain, $mtgName, $witness, $callIn, $modCode)=mysql_fetch_array($res);
			mysql_free_result($res);
		}
		$ix=strpos($mtgName, " - ");
		if ($ix === false) {
			$caseName = $mtgName;
		} else {
			$caseName = substr($mtgName, 0, $ix);
		}
		$subj="Your LiveLitigation Invitations Have Been Sent";

		$sql="select InvStat_Mailto, InvStat_Info, InvStat_Timestamp, InvStat_DisplayDate ".
		"from InvitationStats where Meet_ID='$mtgID'";
		$res=mysql_query($sql,$db);
		if ($res) {
			while(($row=mysql_fetch_assoc($res))!=false) {
				$sdata .= $row['InvStat_Info'];
				if (!$confirmAddr) { $confirmAddr = $row['InvStat_Mailto']; }
				if (!$displayDate) { $displayDate = $row['InvStat_DisplayDate']; }
			}
			mysql_free_result($res);
		}
		$trace="depoInvitationStats() debug: mailto = '$confirmAddr'";
		$this->log2file($this->s_ServiceClass, $trace);
		if (!$confirmAddr) { return $out; }

		$templateEmails="";

		$sql="select Invite_ClientKey, Invite_Email from InvitationList where Meet_ID='$mtgID'";
		$res=mysql_query($sql,$db);
		if ($res) {
			while(($row=mysql_fetch_assoc($res))!=false) {
				if (strpos($sdata, $row['Invite_ClientKey'])) {
					if (strlen($templateEmails)) { $templateEmails.=";"; }
					$email = $row['Invite_Email'];
					$templateEmails.="$email";
				}
			}
			mysql_free_result($res);
		}

		$template = "Please read all of the information below carefully.%0D%0A%0D%0AYou should have received ".
		"an email invitation from invite@livelitigation.com with instructions for joining the LiveLitigation ".
		"event. If it does not appear in your Inbox, please check your Junk/Spam folder.%0D%0A%0D%0AEach participant ".
		"receives a unique email invitation and they cannot be shared.%0D%0A%0D%0AImportant: Please call the LiveLitigation ".
		"Support Department at 818-783-4311 ext 1 to do a Tech Check to ensure that everything goes smoothly on the ".
		"day of the event. The account to reference is $domain.%0D%0A%0D%0AFor help during the event, you can call LiveLitigation ".
		"or use the Help Button (?) to Contact Support. The Help Button is in the top left of the LiveLitigation screen.".
		"%0D%0A%0D%0AThank you,";

		$confirmLink = "mailto:$templateEmails?subject=LiveLitigation Email Confirmation&body=$template";

		$template = str_replace("%0D%0A%0D%0A", "<br/><br/>", $template);

		$confirmMsg = "<div style='font-family:Arial, Helvetica, sans-serif; font-size:12px'>\n".
		"Your email invitations have been sent for the following event:<br/><br/>\n".
		"<b>Case Name:</b> $caseName<br/>\n<b>Witness Name:</b> $witness<br/>";

		if ($displayDate) { $confirmMsg.="\n<b>Date / Time:</b> $displayDate<br/>"; }

		if ($callIn && $modCode) {
			$confirmMsg.="\n<b>Call-in Number / Moderator Code:</b> $callIn / $modCode<br/>";
		} elseif($callIn) {
			$confirmMsg.="\n<b>Call-in Number:</b> $callIn<br/>";
		}

		$confirmMsg.="<br/>\n<table style='font-family:Arial, Helvetica, sans-serif; font-size:12px' ".
		"width=\"600\" border=\"1\" cellspacing=\"0\">\n<tr><th>Group</th><th>Name</th>".
		"<th>Email</th><th>Key</th></tr>\n$sdata\n</table><br/><br/>\nWe highly recommend sending a ".
		"follow-up email to the participants to confirm receipt of their invitation, and to pass along ".
		"additional instructions for testing with our Technical Support department.<br/><br/>\nUse or ".
		"modify the template below or <a href=\"$confirmLink\">Click Here</a> to quickly compose this ".
		"email, addressed to all participants.<br/><br/>\n—————Template—————<br/>\n$template<br/>\n".
		"—————Template—————</div>\n";

		// need to wrap the lines at 800 chars to avoid weird char injection
		$confirmMsg = wordwrap($confirmMsg,800);

		// send status email
		$headers = 'MIME-Version: 1.0' . "\n".
		"From: $inviter\n".
		//"Reply-To: $schEmail\n".
		'Content-Type: text/html; charset="UTF-8"'."\n".
		'Content-Transfer-Encoding: 8bit'."\n";
/*
		$trace="depoInvitationStats() debug: $headers\n$confirmMsg";
		$this->log2file($this->s_ServiceClass, $trace ,'/var/log/httpd/amfphp_debug_log');
*/
		if ($this->smtp['EP_ID']>0) {
			$this->extMail($confirmAddr,$subj,$confirmMsg,$headers);
		} else {
			mail($confirmAddr,$subj,$confirmMsg,$headers,"-f $inviter");
		}
		$out = true;

		$sql="delete from InvitationStats where Meet_ID='$mtgID'";
		mysql_query($sql, $db);
		return $out;
	}

	/**
	* Create DepoEmailNotes record.
	* Adds new record to DB. Requires an object that has the properties:<code>
	* meetID:int
	* groupID:int
	* body:string
	* toAll:int
	* </code>
	*
	* If the <i>toAll</i> property is nonzero, the note will be assigned to
	* all groups in the deposition.
	*
	* @param Object New note object.
	* @return Boolean True on success.
	*/
	function createDepoNote($noteObject) {
		$db = $this->dbh;
		$out = false;
		$reqd = array("groupID", "meetID", "body", "toAll");
		$prop = array_keys($noteObject);
		$fail = array_diff($reqd, $prop);
		if (count($fail)) {
			$trace="createDepoNote() missing field(s): " . print_r($fail, true);
			$this->log2file($this->s_ServiceClass, $trace);
			return $out;
		}
		$gid = mysql_escape_string($noteObject['groupID']);
		$mtg = mysql_escape_string($noteObject['meetID']);
		$txt = mysql_escape_string($noteObject['body']);
		$glb = mysql_escape_string($noteObject['toAll']);
		if (!$txt) {
			// delete if body is empty.
			$sql = "delete from DepoEmailNotes where DCG_ID='$gid' && Meet_ID='$mtg'";
		} else {
			$sql = "replace into  DepoEmailNotes (DCG_ID, Meet_ID, DEN_AllGroups, DEN_Content) ".
			"values ('$gid', '$mtg', '$glb', '$txt')";
		}
		$out = mysql_query($sql, $db);
		if (!$out) {
			$trace="createDepoNote() query failed. SQL: $sql ". mysql_error();
			$this->log2file($this->s_ServiceClass, $trace);
		}
		return $out;
	}

	/**
	* Retrieve deposition email notes.
	* Get email notes for a particular depo. Optionally, a group can be specified to
	* fetch a more focused set of notes (if available).
	*
	* @param int Meeting ID.
	* @param int Optional depo chat group ID.
	* @return String Note text.
	*/
	function getDepoNote($meetID, $group=0) {
		$db = $this->dbh;
		$out = "";
		$sql = "select DEN_Content from DepoEmailNotes where Meet_ID='$meetID' ";
		if ($group) {
			$sql.="&& (DCG_ID='$group' || DEN_AllGroups=1)";
		} else {
			$sql.="&& DEN_AllGroups=1";
		}
		$res=mysql_query($sql,$db);
		if ($res) {
			while (($row=mysql_fetch_array($res))!=false) { $out.=$row[0]; }
			mysql_free_result($res);
		}
		return $out;
	}

	/**
	* Create stream manager keys.
	* Traditionally, keys were created when invitations were sent. However, there may
	* now be cases where invitations are not sent, but stream managers still need
	* access. This method is called after meeting creation if it is a deposition, and
	* Meet_DepoHostInvitations is populated.
	*
	* @param Object Creation data.
	* @return Boolean True on success.
	*/
	function createStreamManagers($data) {
		/* bug 3741 create Stream Manager keys immediately, rather than waiting to send email.
		data object must contain the properties:
			"acct"
			"domain"
			"meetID"
			"emails"
		*/
		$db = $this->dbh;
		$out = false;
		$ok = true;
		$avals = "";
		$ivals = "";

		// enforce required fields.
		$props = array("acct", "domain", "meetID", "emails");
		foreach ($props as $q) {
			if (!isset($data[$q])) { $ok=false; }
		}
		if (!$ok) { return $out; }

		$dom = mysql_escape_string($data['domain']);
		$acct = $data['acct'];
		$mtgID = $data['meetID'];

		$emls = explode(",", $data['emails']);
		foreach ($emls as $mail) {
			$asid = $this->createGUID();
			$dsi=$this->sglAuthToken($mtgID);

			if ($avals) { $avals.=",\n"; $ivals.=",\n"; }

			$usr = mysql_escape_string($mail);
			$avals .= "('$asid','$mtgID','vhost','$usr',utc_timestamp(), $acct)";

			// create InvitationList records
			$link="http://$domain/emaillogin/?dskey=$dsi";
			$ivals .= "('$asid', '$mtgID', '$dsi', '$dom', '$usr', '$link', 1, 1)";
		}
		$asql="insert into APISessions (AS_ID, Meet_ID, AS_Type, AS_UserName, AS_CreateDateTime, ".
			"AS_Account) values $avals";
		$isql="insert into InvitationList (Invite_ID, Meet_ID, Invite_ClientKey, Invite_Domain, ".
			"Invite_Email, Invite_URL, Invite_CanDownloadSubmitted, Invite_CanExhibit) values $ivals";

		if (!mysql_query($asql, $db)) {
			$errs="createStreamManagers() error. Qry: $asql\n".mysql_error();
			$this->log2file($this->s_ServiceClass,$errs);
		}
		$out = mysql_query($isql, $db);
		if (!$out) {
			$errs="createStreamManagers() error. Qry: $isql\n".mysql_error();
			$this->log2file($this->s_ServiceClass,$errs);
		}
		return $out;
	}

	/*
	* New improved invitation function.
	* Invitiations will now consist of an object with the following properties:<ul>
	* <li>mailto:String* - Comma-delimited list of email addresses to send the invitation.</li>
	* <li>body:String* - Body of the message. Can contain HTML.</li>
	* <li>dtstart:String* - Scheduled starting datetime.</li>
	* <li>meetID:int - Meeting ID. Required if sending host-only messages or if registration is required.</li>
	* <li>offset:int - Host's time zone offset (minutes).
	* <li>displayDate:String - Formatted/adjusted representation of scheduled starting datetime.</li>
	* <li>subject:String - The subject of the email.
	* <li>from:String - Formal name of the user issuing the invitation.</li>
	* <li>sendIcal:Boolean - Sends iCal (.ics) event in the email.</li>
	* <li>hostmailto:String - Comma-delimited list of emails that will receive a host-only message.</li>
	* </ul>
	*
	* The properties denoted with an asterisk (<i>mailto</i>,<i>body</i>, and <i>dtstart</i>)
	* are required.
	*
	* <i>NOTE:</i> Outlook is known to impose its own formatting on iCal events. The appearance
	* of the message, therefore, is not guaranteed to be consistent with what was sent in the body.
	*
	* Added 2 Aug 2010: If registration is required, the body of the message should contain a
	* _REGISTRATION_ tag. A unique link will be generated and sent in each invitation.
	*
	* Update 1 Oct 2010: The <i>from</i> property may now contain an email address in angle-brackets
	* if desired. This will allow the sender more flexibility in email origination.
	*
	* @param Object
	* @return Boolean
	*/
	function invitations($o_Invite) {
		if (isset($o_Invite['mailto']) && isset($o_Invite['body']) && isset($o_Invite['dtstart'])) {
			$subj="Meeting Invitation";
		} else {
			return false;
		}
		$frName="";
		$isIcal=false;
		if (isset($o_Invite['sendIcal'])) { $isIcal=$o_Invite['sendIcal']; }
/*
		$dbg="invitations() debug:\n".$o_Invite['body'];
		$this->log2file($this->s_ServiceClass,$dbg,'/var/log/httpd/amfphp_debug_log');
*/
		$mtgID=$o_Invite['meetID'];
		// get smtp configuration
		$smtpOK=$this->smtpConfig($o_Invite['meetID']);
		if (!$smtpOK) {
			$err="invitations() failed. Could not determine mail config.";
			$this->log2file($this->s_ServiceClass, $err);
			if (defined("THROW_ERRORS")) { throw new Exception($err); }
			return $smtpOK;
		}
		$emlConfig=$this->smtp;

		// private branded?
		$isPB=$this->isPrivateBranded();
		$dbg="invitations() debug. Meeting: {$o_Invite['meetID']} Private branded: $isPB.\n";
		$this->log2file($this->s_ServiceClass,$dbg,$this->debugLog);
		// $frDomain=($isPB)?"videoconferencinginfo":"megameeting";
		$inviter='invite@'.MAILDOMAIN;
		/*
		select Meet_CallInNumber, Meet_ModeratorCode from Meetings where Meet_ID=
		*/

		/* determine fromName, sender email */
		$tag=strpos($o_Invite['from'],'<');
		if ($tag===false) {
			// get account login info
			$frName=$o_Invite['from'];
			// 29 Aug 2013 - Virgil S. Palitang
			// Since smtpConfig() is modded, host name and email is already set up.
			// $sndr=$this->getHostEmail($o_Invite['meetID']);
			$sndr = $emlConfig['AL_Email'];
		} else {
			$frName=trim(substr($o_Invite['from'],0,$tag));
			$tag++;
			$epos=strpos($o_Invite['from'],'>',$tag);
			if ($epos===false) { $sndr=substr($o_Invite['from'],$tag); }
			else {
				$ln=$epos-$tag;
				$sndr=substr($o_Invite['from'],$tag,$ln);
			}
		}

		$dbg="invitations() debug. mtg: ".$o_Invite['meetID'].", inviter: ".
			$o_Invite['from']." $inviter, reply-to: $sndr";
		$this->log2file($this->s_ServiceClass,$dbg,'/var/log/httpd/amfphp_debug_log');

		ini_set("sendmail_from",$inviter);
		if (isset($o_Invite['subject'])) { $subj=$o_Invite['subject']; }

		// 'Sender: '.$sndr."\n".
		$headers  = 'MIME-Version: 1.0' . "\n".
		"From: \"$frName\"<$inviter>\n".
		'Reply-To: '.$sndr."\n";
		/*
		if ($isIcal) {
			$icsbody=$this->createIcal($o_Invite['dtstart'],$o_Invite['mailto'],$sndr);
		}
		*/

		$addys=explode(',',$o_Invite['mailto']);
		$tpl=$o_Invite['body'];
		$status=false;
		$user="";
		foreach ($addys as $rcpt) {
			$hmm="PX".(rand(1,899)+99);
			$rhash=uniqid($hmm,true);
			if ($isIcal) {
				$hdx='Content-type: multipart/alternative; '.
				'boundary="PHPMailer-alt-'.$rhash.'"'."\n".
				"Content-class: urn:content-classes:calendarmessage\n";
			} else {
				$hdx='Content-Type: text/html; charset="UTF-8"'."\n".
				'Content-Transfer-Encoding: 8bit'."\n";
			}

			/* emails can look like: my name <myemail@mydomain.com>, or myemail@mydomain.com */
			$rawEmail = trim($rcpt);
			$tag=strpos($rcpt,'<');
			if ($tag===false) {
				// $user=str_replace('@','_at_',$rcpt);
				$user=urlencode($rawEmail);
			} else {
				$tmp=trim(substr($rcpt,0,$tag));    // get the user's formal name
				$user=urlencode($tmp);
				$user=str_replace('+','%20',$user);
				$rawEmail=trim(substr($rcpt,$tag)," <>");
			}
			if (strpos($tpl,"_REGISTRATION_")) {
				$fullUser="";
				if ($tag) { $fullUser=$tmp; }
				// $regid=$this->createRegistration($o_Invite['meetID'],$rawEmail,$fullUser);
				$rlink=$this->getRegistrationLink($o_Invite['meetID'], $rawEmail);
				$tpl=str_replace("_REGISTRATION_",$rlink,$tpl);
			}
			$uumail=urlencode($rawEmail);
			if ($tag===false) { $eml=str_replace('&name=_USER_','',$tpl); }
			else { $eml=str_replace("_USER_",$user,$tpl); }
			$eml=str_replace("_EMAIL_",$uumail,$eml);
			$eml=str_ireplace("<br>","<br>\n",$eml);
			$eml=str_replace("&apos;","&#039;",$eml);
			if ($isIcal) {
				$icsbody=$this->createIcal($o_Invite['dtstart'],$o_Invite['mailto'],$sndr,$eml);
			}
			$eml.=$this->getFooter();
			$eml.=$this->emailTrack($mtgID, $rcpt);
			$msgbody="<html><body>$eml</body></html>";
		/*
		*/
/*
		$dbg="invitations() debug:\n$msgbody";
		$this->log2file($this->s_ServiceClass,$dbg,'/var/log/httpd/amfphp_debug_log');

// header stuff
Content-class: urn:content-classes:calendarmessage

Content-Type: text/html; charset="iso-8859-1"
Content-Transfer-Encoding: 7bit

--PHPMailer-alt-$rhash--
*/

			if ($isIcal) {
				// updated 8 Jan 2013. Virgil S. Palitang
				// createIcal will now convert the html to plain text
				// $icsbody=$this->createIcal($o_Invite['dtstart'],$o_Invite['mailto'],$sndr);
				// $icsbody=$this->createIcal($o_Invite['dtstart'],$o_Invite['mailto'],$sndr,$msgbody);

				$mailContents=<<<END_MAIL_ICAL
--PHPMailer-alt-$rhash
Content-Type: text/html; charset="UTF-8"
Content-Transfer-Encoding: 8bit

$msgbody

--PHPMailer-alt-$rhash
Content-Type: text/calendar; name="meeting.ics"; method=REQUEST
Content-Transfer-Encoding: 8bit

$icsbody

END_MAIL_ICAL;

/*
				$dbg="invitations() debug:\n$mailContents";
				$this->log2file($this->s_ServiceClass,$dbg,'/var/log/httpd/amfphp_debug_log');
*/
			} else {
				$mailContents=<<<END_MAIL

$msgbody

END_MAIL;
			}
			// sendmail does not like lines longer than 998 chars.
			$mailContents=wordwrap($mailContents,160);

			$addlHdrs=$headers.$hdx;
			if ($this->smtp['EP_ID']>0) {
				$status=$this->extMail($rcpt,$subj,$mailContents,$addlHdrs);
			} else {
				$status=(mail($rcpt,$subj,$mailContents,$addlHdrs,"-f $inviter"))?1:0;
			}
			if (!$status) {
				$this->log2file($this->s_ServiceClass,"invitations() failed for $rcpt");
			}

			// bug 1941 - store email for later reference
			$trace="invitations() debug - $mtgID:$rcpt";
			$this->log2file($this->s_ServiceClass, $trace);
			$sentObj=array(
				"meetID" => $mtgID,
				"email" => $rcpt,
				"success" => $status
			);
			if ($tmp != $rawEmail) { $sentObj['name'] = $tmp; }
			$this->logSentEmail($sentObj);
		}


		// debug last message
		/*
		$dbg="invitations() debug:\n$mailContents";
		$this->log2file($this->s_ServiceClass,$dbg,'/var/log/httpd/amfphp_debug_log');
		*/

		if (isset($o_Invite['hostmailto'])) {
			$this->hostInstructions($o_Invite,$isPB);
		}

		return $status;
	}

	private function hostInstructions($obj,$isPB) {
		$db=$this->dbh;
		$mtgID=$obj['meetID'];
		$hlist=explode(',',$obj['hostmailto']);
		$tzo=$obj['offset'] * -1;
		if (!is_array($hlist) || count($hlist)<1) { return; }
		$subj="Meeting invitation";
		if (isset($obj['subject'])) { $subj=$obj['subject']; }
		// $frDomain=($isPB)?"videoconferencinginfo":"megameeting";
		$inviter='invite@'.MAILDOMAIN;
		ini_set("sendmail_from",$inviter);

		// smtp config should be set by now.

		$meetName="";
		$meetTime="";
		$tcInfo="";
		$sql="select a.Acc_ID, Acc_Domain,date_add(Meet_ScheduledDateTime,interval $tzo minute) ".
		"as stime, Meet_Name, AL_Email,Meet_CallInNumber, Meet_ModeratorCode ".
		"from Meetings a, Accounts b, AccountLogins c where ".
		"a.Acc_ID=b.Acc_ID && b.Acc_ID=c.Acc_ID && Meet_ID=$mtgID && a.AL_ID=c.AL_ID";
		$res=mysql_query($sql,$db);
		if ($res) {
			$row=mysql_fetch_assoc($res);
			$acct=$row['Acc_ID'];
			$meetName=$row['Meet_Name'];
			$meetTime=$row['stime'];
			$schEmail=$row['AL_Email']; // scheduler email
			$tcNum=$row['Meet_CallInNumber'];
			$tcAcc=$row['Meet_ModeratorCode'];
			mysql_free_result($res);
		}
		if (isset($obj["displayDate"])) { $meetTime=$obj["displayDate"]; }
		if ($tcNum) { $tcInfo="<p><b>Call In Number/Access code:</b> $tcNum / $tcAcc</p>\n"; }

		$frName="";
		if (isset($obj['from'])) { $frName=$obj['from'].' '; }
		// send host instructions
		/*
		*/
		$headers  = 'MIME-Version: 1.0' . "\n".
		"From: $frName<$inviter>\n".
		"Reply-To: $schEmail\n".
		'Content-Type: text/html; charset="UTF-8"'."\n".
		'Content-Transfer-Encoding: 8bit'."\n";
		$footer=$this->getFooter();
		foreach ($hlist as $rcpt) {
			trim($rcpt);
			// User name
			$tag=strpos($rcpt,'<');
			if ($tag===false) { $hostUser=$rcpt; }
			else { $hostUser=trim(substr($rcpt,0,$tag)); }
			$usr=mysql_escape_string($hostUser);

			// new validation code
			$asid=$this->createGUID();
			$vc=$this->makeVcode($asid);
			$sql="insert into APISessions (AS_ID, Meet_ID, AS_Type, AS_UserName, AS_CreateDateTime, ".
			"AS_Account) values ('$asid','$mtgID','vhost','$usr',utc_timestamp(), $acct)";

			// debug APISessions sql
			$dbg="hostInstructions() APISessions debug:\n$sql";
			$this->log2file($this->s_ServiceClass,$dbg,'/var/log/httpd/amfphp_debug_log');

			if (!mysql_query($sql,$db)) {
				$errs="hostInstructions() SQL:\n$sql".mysql_error();
				$this->log2file($this->s_ServiceClass,$errs);
			}

			$link='http://'.$row['Acc_Domain']."/emaillogin/?sid=$asid";
			$subj1="$subj - Host";
			$msg1=<<<END_EMAIL
<html><body style='font-family:Arial, Helvetica, sans-serif; font-size:12px'>
<p>You have been invited to <b>host</b> an Internet Video/Web meeting.</p>
<p><b>Meeting name:</b> $meetName</p>
<p><b>Date/Time:</b> $meetTime</p>
$tcInfo

<p><b>To join the meeting as a host, click here:</b>
<a href='$link'>Join $meetName</a></p>

<p>Note: You will need the separately provided password to enter this meeting as a host.
If you have not received this password, please contact the <a href='mailto:$schEmail'>
person who scheduled this meeting</a>.
</p>
$footer
</body></html>
END_EMAIL;

			// $status=mail($rcpt,$subj,$msg1,$headers);
			if ($this->smtp['EP_ID']>0) {
				$this->extMail($rcpt,$subj1,$msg1,$headers);
			} else {
				mail($rcpt,$subj1,$msg1,$headers,"-f $inviter");
			}

			$subj2="$subj - Host Password";
			$msg2=<<<END_FOLLOW
<html><body style='font-family:Arial, Helvetica, sans-serif; font-size:12px'>
<p>You have been invited to host an Internet Video/Web meeting. Please use the password
below when entering the meeting as a host. A link to the meeting is provided to you in a
separate email for security reasons. If you have not received this meeting link please
contact the <a href='mailto:$schEmail'>person who scheduled this meeting</a>.</p>

<p><b>Password:</b> <code>$vc</code>
<br>(The system is case-sensitive. Please be sure to enter the password exactly as it appears.)</p>
$footer
</body></html>
END_FOLLOW;

			// $status=mail($rcpt,$subj,$msg2,$headers);
			if ($this->smtp['EP_ID']>0) {
				$this->extMail($rcpt,$subj2,$msg2,$headers);
			} else {
				mail($rcpt,$subj2,$msg2,$headers,"-f $inviter");
			}
		}
	}

	private function getRegistrationLink($meetID,$email) {
		$db=$this->dbx;
		$out="";
		$sql="select Acc_Domain from Accounts a, Meetings b where ".
		"a.Acc_ID=b.Acc_ID && Meet_ID=$meetID";
		$res=mysql_query($sql,$db);
		if ($res) {
			$row=mysql_fetch_array($res);
			mysql_free_result($res);
			$ueml=urlencode($email);
			$out="http://".$row[0]."/registration/?id=$meetID&email=$ueml";
		}
		return $out;
	}

	function chkMeetings($s_MtgName, $n_Alid) {
		$db=$this->dbx;
		$safeName=mysql_real_escape_string($s_MtgName,$db);
		$out=0;
		$sql="select count(*) from Meetings where Meet_Name='$safeName' && AL_ID=$n_Alid && Meet_EnableJoin=1";
		$res=mysql_query($sql,$db);
		if ($res) {
			$row=mysql_fetch_array($res);
			$out=intval($row[0]);
			mysql_free_result($res);
		}
		return $out;
	}

	/**
	* Check for meeting name duplicates.
	* Requires meeting name and account ID, optional account Login ID for login specific checks.
	* Returns boolean true if an active meeting exists under the specified account.
	* @param String Meeting name.
	* @param int Account ID.
	* @param int Login ID.
	* @return Boolean
	*/
	function chkMeetingName($s_MtgName, $n_AccID, $n_ALID=0) {
		$db=$this->dbx;
		$escapedName=mysql_real_escape_string($s_MtgName,$db);
		$out=0;
		if (isset($n_ALID) && $n_ALID > 0) {
			$alid = mysql_escape_string($n_ALID);
			$login = "&& AL_ID = '$alid'";
		} else {
			$login = "";
		}
		$sql="SELECT COUNT(*) FROM Meetings WHERE Meet_Name='$escapedName' && Acc_ID=$n_AccID $login && Meet_EnableJoin=1";
		$res=mysql_query($sql,$db);
		if ($res) {
			$row=mysql_fetch_array($res);
			$out=intval($row[0]);
			mysql_free_result($res);
		}
		return (bool)$out;
	}

	/**
	* Get a meeting's ID.
	* Searches the active meeting list by name and login ID. Returns a meeting ID
	* if successful, otherwise zero.
	*
	* @param String Meeting name.
	* @param int Account login ID.
	*/
	function getMeetingID($s_MtgName, $n_Alid) {
		$db=$this->dbh;
		$out=0;
		$mtg=mysql_escape_string($s_MtgName);
		$sql="select Meet_ID from Meetings where Meet_Name='$mtg' && AL_ID='$n_Alid' ".
			"&& Meet_EnableJoin=1";
		$res=mysql_query($sql,$db);
		if ($res) {
			$row=mysql_fetch_array($res);
			$out=(int)$row[0];
			mysql_free_result($res);
		}

		return $out;
	}

	/**
	* Log an email invitation.
	* Call this when an invitation is sent. The argument should be an object that
	* follows the structure:<code>
	* meetID:int - Required. The meeting connected to the invitation.
	* email:String - Required. The email address.
	* name:String - Optional. The user name.
	* key:String - Optional. LiveDeposition client key.
	* optional:String - Optional data that may be used later.
	* </code>
	*
	* This method generally doesn't need to be accessed by a swf. It is primarily for
	* internal use, and is only publicly available because the Host API needs it.
	*
	* @param Object Contains info about the sent invitation.
	* @return Boolean true on success.
	*/
	function logSentEmail($lgRecord) {
		$db=$this->dbh;
		$mid=$lgRecord['meetID'];
		$eml=mysql_escape_string($lgRecord['email']);
		$key='';
		$usr='';
		$opt="";
		$snt=1;
		$out=false;
		if (!isset($lgRecord['meetID']) || !isset($lgRecord['email'])) {
			if (defined("THROW_ERRORS")) { throw new Exception("Missing required field."); }
			return $out;
		}
		if (isset($lgRecord['key'])) { $key=mysql_escape_string($lgRecord['key']); }
		if (isset($lgRecord['success'])) { $snt=intval($lgRecord['success']); }
		if (isset($lgRecord['optional'])) { $opt=mysql_escape_string($lgRecord['optional']); }
		if (isset($lgRecord['name'])) { $usr=mysql_escape_string($lgRecord['name']); }
		$sql="replace into SentInvite (Meet_ID, Sent_Email, Sent_Name, Sent_Key, ".
		"Sent_Optional, Sent_Success) ".
		"values ('$mid','$eml','$usr','$key', '$opt', '$snt')";
		$out=mysql_query($sql,$db);
		if (!$out) {
			$trace="Error. ".mysql_error();
			$this->log2file($this->s_ServiceClass,"logSentEmail() $trace");
			if (defined("THROW_ERRORS")) { throw new Exception($trace); }
		}
		return $out;
	}

	/**
	* Get info on sent emails for a specific meeting.
	* Returns a list of objects describing invitation emails that have
	* been sent for this meeting. Each object has the structure:<code>
	* Sent_Email:String
	* Sent_Name:String
	* Sent_Time:String
	* Sent_Success:int - 1 or 0.
	* Sent_Received:int - 1 or 0.
	* </code>
	*
	* @param int Meeting ID.
	* @return Array List of sent email objects.
	*/
	function sentEmailsByMeeting($meetID) {
		$db = $this->dbx;
		$out = array();
		$mtg = mysql_escape_string($meetID);
		$sql = "select Sent_Email, Sent_Name, unix_timestamp(Sent_Time) as sntime, ".
		"Sent_Success, Sent_Received from SentInvite where Meet_ID='$mtg' && Sent_Email!=''";
		$res=mysql_query($sql,$db);
		if ($res) {
			while(($row=mysql_fetch_assoc($res))!=false) {
				// convert timestamp to utc
				$tmp=$row['sntime'];
				unset($row['sntime']);
				$row['Sent_Time'] = gmdate("Y-m-d H:i:s", $tmp);
				$out[]=$row;
			}
			mysql_free_result($res);
		}
		return $out;
	}

	/**
	* Get info on sent emails from a specified host.
	* Returns a list of objects describing invitation emails that have
	* been sent by a specified login. Only active meetings that belong
	* to the login will be included in the results. This method is
	* similar to sentEmailsByMeeting() but with an expanded scope. Each
	* item in the returned object has the structure:<code>
	* Meet_ID:int
	* Meet_Name:String
	* SentInfo:Array of Objects
	* </code>
	*
	* Each item (Object) in the SentInfo array has the structure:<code>
	* Sent_Email:String
	* Sent_Name:String
	* Sent_Time:String
	* Sent_Success:int - 1 or 0.
	* Sent_Received:int - 1 or 0.
	* </code>
	*
	* The 2nd parameter indicates the meeting type that will be included
	* in the list.<ul>
	* <li>0 = Standard meeting.</li>
	* <li>1 = Deposition.</li>
	* <li>2 = Both types.</li>
	* </ul>
	*
	* Parameter is optional and defaults to zero (standard meetings).
	*
	* @param int AccountLogin ID
	* @param int Meeting type.
	* @return Array List of objects.
	*/
	function sentEmailsByLogin($alid, $depoFlag=0) {
		$db = $this->dbx;
		$out = array();
		$lgn = mysql_escape_string($alid);
		$wcl = "a.Meet_ID=b.Meet_ID && AL_ID='$lgn' && Sent_Email!='' && Meet_EnableJoin=1 ";
		if ($depoFlag==1) { $wcl .= "&& Meet_EnableDepo=1 "; }
		if ($depoFlag==0) { $wcl .= "&& Meet_EnableDepo=0 "; }
		$sql = "select a.Meet_ID, Meet_Name, Sent_Email, Sent_Name, unix_timestamp(Sent_Time) as snt, ".
		"Sent_Success, Sent_Received from SentInvite a, Meetings b where $wcl".
		"order by Meet_ID";
		$res=mysql_query($sql,$db);
		if ($res) {
			$lastID = 0;
			$props = array("Sent_Email", "Sent_Name", "Sent_Success", "Sent_Received");
			$sentInfo = array();
			while(($row=mysql_fetch_assoc($res))!=false) {
				$mtg = $row['Meet_ID'];
				if ($mtg != $lastID) {
					if ($tmpObj) { $out[] = $tmpObj; }
					$tmpObj = array(
						"Meet_ID" => $row['Meet_ID'],
						"Meet_Name" => $row['Meet_Name']
					);
				}
				// turn the timestamp into a UTC datetime
				$sentInfo['Sent_Time'] = gmdate("Y-m-d H:i:s", $row['snt']);
				foreach ($props as $col) { $sentInfo[$col] = $row[$col]; }
				$tmpObj['SentInfo'] = $sentInfo;
			}
			if ($tmpObj) { $out[] = $tmpObj; }
			mysql_free_result($res);
		}
		return $out;
	}

	/* - - - - - teleconferencing functions - - - - - */

	/**
	* Get teleconferencing codes.
	* Updated method for retrieving teleconferencing codes. Only requires a valid
	* account login id since the region and branding can be determined from that.
	* Additionally, the new teleconferencing profiles will be used which provides
	* other accounts usage of the teleconferencing api.
	* Returns an object that follows the structure:<code>
	* {
	* phonenumber:string,
	* modcode:string,
	* attcode:string
	* } </code>
	*
	* @param int Account login ID.
	* @return Object.
	*/
	function createTCCodes($alid,$tollfree=0) {
		$db=$this->dbh;
		$tctype=($tollfree>0)?"tollfree":"toll";
		$alColumn=($tollfree>0)?"AL_TCProfile":"AL_TCTollProfile";
		$sql="select Acc_EnablePrivateBranded, $alColumn, c.Acc_Region, AL_Active from ".
		"AccountLogins a, Accounts b, AccountSettingProfiles c where a.Acc_ID=b.Acc_ID ".
		"&& a.Asp_ID=c.Asp_ID && AL_ID='$alid'";
		$actv=0;
/*
// debugging
		$dx="createTCCodes() debug 1. $sql";
		$this->log2file($this->s_ServiceClass,$dx);
*/
		$res=mysql_query($sql,$db);
		if ($res) {
			list($pb, $tpro, $rgn, $actv)=mysql_fetch_array($res);
			mysql_free_result($res);
		}
		$tcProfileID=$tpro;
		if ($actv<1) {
			$err="createTCCodes(). $alid - invalid or inactive login.";
			$this->log2file($this->s_ServiceClass,$err);
			if (defined("THROW_ERRORS")) { throw new Exception($err); }
			return false;
		}
		// return false if trying to get a tollfree number without a TCProfile
		/* This shouldn't happen - Toll profiles should be inherited from the account.
		if ($tollfree>0 && $tpro<1) {
			$err="createTCCodes() failed. Tollfree request without valid teleconferencing profile.";
			$this->log2file($this->s_ServiceClass,$err);
			if (defined("THROW_ERRORS")) { throw new Exception($err); }
			return false;
		}

		// default profile
		if ($tpro<1 || $tollfree==0) {
			$tpro=($rgn=='UK')?2:1;
		}
		*/
		// public, private, or default
		$grp="default";
		if ($tpro<6 || $tpro==26) {
			$grp=($pb>0)?"private":"public";
		}
		// load the profile
		$sql="select * from TeleconferenceProfiles where TCProfile_ID=$tpro";
/*
// debugging
		$dx="createTCCodes() debug 2. $sql";
		$this->log2file($this->s_ServiceClass,$dx);
*/

		$res=mysql_query($sql,$db);
		if ($res) {
			$tp=mysql_fetch_assoc($res);
			mysql_free_result($res);
		}
		$moreParams=$tp['TCProfile_Params'];

		$tcObj=new Teleconference();
		$tcObj->buildConnector($tp['TCProfile_Url'],$tp['TCProfile_User'],$tp['TCProfile_Pass']);

		// get teleconf #
		$tcn=''; $age=1;
		$sql="select TCNumber_Data, datediff(now(),TCNumber_Timestamp) as age ".
		"from TeleconferenceNumbers where TCProfile_ID=$tpro && TCNumber_Group='$grp'";
		$res=mysql_query($sql,$db);
		if ($res) {
			if (mysql_num_rows($res)) { list($tcn,$age)=mysql_fetch_array($res); }
			mysql_free_result($res);
		}

/*
// debugging
		$dx="createTCCodes() debug 3. $sql";
		$this->log2file($this->s_ServiceClass,$dx);
*/
		if ($age>0) {
			// get new numbers
			$func=$tp['TCProfile_Parser'];
			if ($func == "parseTwilio") {
				$dat = $tcObj->twilioNumbers($tp['TCProfile_Type']);
			} else {
				$dat=$tcObj->getNumbers();
			}
/*
// debugging
		$dx="createTCCodes() debug 4. call tcObj.$func";
		$this->log2file($this->s_ServiceClass,$dx);
*/
			$phObj=$tcObj->$func($dat);

/*
// debugging
		$dbx=print_r($phObj,true);
		$dx="createTCCodes() debug 5. $dbx";
		$this->log2file($this->s_ServiceClass,$dx);
*/


			$dsql="delete from TeleconferenceNumbers where TCProfile_ID=$tpro";
			mysql_query($dsql,$db);

			$ins="insert into TeleconferenceNumbers (TCProfile_ID, TCNumber_Data, TCNumber_Group) values ";
			foreach ($phObj as $kgrp=>$vnum) {
				if ($ct) { $ins.=","; }
				$ins.="($tpro,'$vnum','$kgrp')";
				// let's use the new data if applicable
				if ($kgrp == $grp) { $tcn=$vnum; }
				$ct++;
			}
			// don't insert if tcn has no value
			if ($tcn) { mysql_query($ins,$db); }
		}
		// should have a phone number by now. If not, bail.
		if (!$tcn) {
			$err="createTCCodes() could not determine phone number.";
			$this->log2file($this->s_ServiceClass,$err);
			if (defined("THROW_ERRORS")) { throw new Exception($err); }
			return false;
		}
		// now we get the codes
		/* ignore additional params until the TC provider can get their api stuff straight.
		if(strlen($moreParams)>0) {
			if (substr($moreParams,0,1)=='&') { $tcn.=$moreParams; }
			else { $tcn.="&$moreParams"; }
		}
		*/
		$myAPC = "";
		$myEmail = "";
		if ($tp['TCProfile_Url'] == 'aplus') {
			$this->telecon = $tcObj;    // Set the global teleconference object.
			// Profiles that use a custom APC code will have "client=APCxxxxx" in params
			if (strlen($moreParams)>0) {
				// If moreParams is a query string, we parse it into paramsList.
				// Otherwise, we just send the entire string.
				parse_str($moreParams, $paramList);
				$myAPC = (isset($paramList['client']))? $paramList['client'] : $moreParams;
				$myEmail = (isset($paramList['email']))? $paramList['email'] : "";
				$raw = $this->reserveAPlusCodes($alid,$tollfree,$myAPC, $myEmail,"NY");
			} else {
				$raw = $this->reserveAPlusCodes($alid,$tollfree,"1A", "", "NY");
			}
		} elseif ($tp['TCProfile_Url'] == 'freeConf') {
			$fcObj=$tcObj->createFreeConfCode();
			if ($fcObj) { $raw=$this->procFCObj($alid, $fcObj); }
			else { $raw="No results from FreeConference"; }
		} elseif ($tp['TCProfile_Url'] == 'https://api.twilio.com/2010-04-01') {
			$tnum = preg_replace('/\D/','', $tcn);
			$raw = $this->reserveTwilio($tpro, $tnum);
		} else { $raw=$tcObj->getCode($tcn); }

		$out=$this->parseCodes($raw);

		if (!isset($out['modcode'])) {
			$err="createTCCodes() error. $raw";
			$this->log2file($this->s_ServiceClass,$err);
		} else {
			$out['TCProfile_ID'] = !empty($out['TCProfile_ID']) ? $out['TCProfile_ID']: $tcProfileID;
		}
		return $out;
	}

	private function getPair($alid, $conf) {
		$db=$this->dbh;
		$out = array();
		$loq = "lock tables APlusCodes write";
		mysql_query($loq, $db);
		$sql="select APN_Code from APlusCodes where AL_ID=0 && APConf_ID=0 && (APN_Code not in (".
		"27152557, 27174681, 27175634, 27301609, 27364795, 27479219, 27871172, 27989434)) ".
		"order by rand() limit 2";
		$res=mysql_query($sql,$db);
		if ($res) {
			while(($row=mysql_fetch_array($res))!=false) { $out[]=$row[0]; }
			mysql_free_result($res);
		}
		if (count($out)) {
			$codes=implode(',', $out);
			$sql = "update APlusCodes set AL_ID='$alid', APConf_ID='$conf' where APN_Code in ($codes)";
			mysql_query($sql, $db);
		}
		$loq = "unlock tables";
		mysql_query($loq, $db);
		return $out;
	}

	private function reserveAPlusCodes($alid, $tollfree=0, $acctAPC="1A", $email="", $bridge="WA") {
		$db = $this->dbh;
		$tcObj = $this->telecon;
		$aphone = array (
			"WA" => array("2064020823", "8884120872"),
			"NY" => array("7162731380", "8445793641"),
		);

		$raw = "";
		$opresult = "";
		$notice = "";
		$apzone = "WA";
		$callIn = $aphone[$apzone][$tollfree];
		$ucodes = $this->aplusUnified($alid);   // adds record to APlusUnified if none exists.
/* debugging
		$dbg = "aplusUnified($alid) => $ucodes";
		$this->log2file($this->s_ServiceClass, "reserveAPlusCodes() debug - $dbg");
*/
		$tcProfileID='';
		// Result should be [confID:modCode:attCode]
		if (strpos($ucodes,":")===false) {
			$raw="Error getting teleconference codes. No valid data.";
		} else {
			$retry = 2;
			while ($retry > 0) {
				if (!$ucodes) { $ucodes = $this->aplusUnified($alid); }
				if (strpos($ucodes,":")===false) { $retry--; sleep(3); continue; }
				list($cnf, $mod, $att) = explode(":", $ucodes, 3);

				// do replicated APlus - start with some basic info
				$baseInfo = array(
					"zone" => $apzone,
					"modCode" => $mod,
					"attCode" => $att,
					"confID" => $cnf,
					"endpoint" => $callIn,
				);
				if ($email) { $baseInfo['email'] = $myEmail; }
				if ($acctAPC!="1A") { $baseInfo['acctID'] = $acctAPC; }
/* debugging
			$dbg = print_r($baseInfo, true);
			$this->log2file($this->s_ServiceClass, "reserveAPlusCodes() debug1 - $dbg");
*/

				// set up WA bridge
				$aplusObj = $tcObj->createAPlusObj($baseInfo);
/* debugging
			$dbg = print_r($aplusObj, true);
			$this->log2file($this->s_ServiceClass, "reserveAPlusCodes() debug2 - $dbg");
*/

				$xmlres = $this->sendAPlusRequest($apzone, $aplusObj);
/* debugging
			$trace= "sendAPlusRequest - more debugging. Result: ".$xmlres->result;
			$this->log2file($this->s_ServiceClass, $trace);
*/

				if (isset($xmlres->result)) { $opresult = trim(strtolower($xmlres->result)); }
				$b1res = ($opresult == "success")? 1 : 0;
				if ($b1res < 1) {
					// notifyDev($msg);
					$notice .= "Error attempting to create Conf: $cnf ".
					"with codes $mod, $att ($apzone). Please check for DB usage, ".
					"and adjust appropriately. Current result: ";
					if ($opresult == "failure") { $notice .= $xmlres->message; }
					else { $notice .= "(API unavailable)"; }
					$notice.="\n\n";

					/*
					Codes should be replicated, but if we wanted a WA code, and
					WA API is down, then (if possible) return a NY phone so the
					user doesn't go home empty handed.
					*/
					$trace="reserveAPlusCodes() Error. $apzone.";
					$this->log2file($this->s_ServiceClass, $trace);
					if ($bridge=="WA") { $bridge = "NY"; }
				}

				/* replicate to NY bridge */
				$apzone = "NY";
				$baseInfo['zone'] = $apzone;
				$baseInfo['endpoint'] = $aphone[$apzone][$tollfree];
				$aplusObj = $tcObj->createAPlusObj($baseInfo);
				$xmlres = $this->sendAPlusRequest($apzone, $aplusObj);
				$opresult = ""; // reset opresult so non-results don't cause inaccurate return.
				if (isset($xmlres->result)) { $opresult = trim(strtolower($xmlres->result)); }
				$b2res = ($opresult == "success")? 1 : 0;

				if ($b2res < 1) {
					$notice .= "Error attempting to create Conf: $cnf ".
					"with codes $mod, $att ($apzone). Please check for DB usage, ".
					"and adjust appropriately. Current result: ";
					if ($opresult == "failure") { $notice .= $xmlres->message; }
					else { $notice .= "(API unavailable)"; }
					$notice.="\n\n";

					$trace="reserveAPlusCodes() Error. $apzone.";
					$this->log2file($this->s_ServiceClass, $trace);
					if ($bridge=="NY") { $bridge = "WA"; }
				}
				/* replecate to new bridge */

				/* if both bridge failed replicate to freeConference bridge */
				if (($b1res + $b2res) < 1) {
					$apzone='freeConf';
					$fcObj=$tcObj->createFreeConfCode();
					if ($fcObj) {
						$tcProfileID=25;
						$raw=$this->procFCObj($alid, $fcObj);
						$raw.=' '.$tcProfileID;
						$out=$this->parseCodes($raw);
						$mod=$out['modcode'];
						$att=$out['attcode'];
						$aphone[$bridge][$tollfree]=$out['phonenumber'];
						$b3res=1;
					}
					if ($b3res < 1) {
						$notice .= "Error attempting to create Conf: $cnf ".
						"with codes $mod, $att ($apzone). Please check for DB usage, ".
						"and adjust appropriately. Current result: ";
						if ($opresult == "failure") { $notice .= $xmlres->message; }
						else { $notice .= "(API unavailable)"; }
						$notice.="\n\n";
						$trace="reserveAPlusCodes() Error. $apzone.";
						$this->log2file($this->s_ServiceClass, $trace);
					}
			}

				// If neither bridge succeeded, give up.
				if (($b1res + $b2res + $b3res) < 1) {
					$ccc="update APlusUnified set AL_ID=0 where APU_ID = '$cnf'";
					mysql_query($ccc, $db);
					$raw = "Error. Unable to retrieve codes.";
					$ucodes = "";   // reset, sleep, and try again (maybe)
					sleep(2);
				}
				else {
					if($b3res != 1) {
						// update APlusUnified
						$ccc="update APlusUnified set APU_Bridge1='$b1res', APU_Bridge2='$b2res' ".
						"where APU_ID = '$cnf'";
						mysql_query($ccc, $db);
					}
					$raw = "SUCCESS,OK ".$aphone[$bridge][$tollfree]." $mod $att ";
					if (!empty($tcProfileID)) {
						$raw .= $tcProfileID;
					}
					$retry = 0;
				}
				$retry--;
				$apzone = "WA";
			} // end while
			if ($notice)  { $this->devNotify("APlus API error(s)", $notice); }
			}
		return $raw;
	}

	private function reserveAPlusCodesOld($alid, $tollfree=0, $acctAPC="1A", $email="") {
		$db=$this->dbh;
		// first, get a conference ID
		/*
			this will need to be updated in the future
		*/
		$apcid=2700000;
		$out="Failed";
		$modCode=0;
		$attCode=0;
		/*
		$sql="insert into APlusConfIDs (AL_ID, APConf_Datetime) values ".
		"('$alid', utc_timestamp())";
		$ok=mysql_query($sql,$db);
		if ($ok) { $apcid=mysql_insert_id(); }  // store new conf id in memory (apcid)

		time to recycle apconf_ids
		*/
		$cfid = $this->reuseAPConf($alid);
		// reuseAPConf might return APConf_ID:ModCod:AttCode
		$uni = strpos($apcid, ":");
		if ($uni === false) {
			$apcid = $cfid;
		} else {
			$apair = explode(":", $cfid);
			$apcid = array_shift($apair);
			// list($apcid, $modCode, $attCode) = explode(":", $cfid);
		}

		if (!$email) { $email = 'noreply@megameeting.com'; }

		$wsdl="http://4.14.61.36:8080/ccws/services/SubscriberWebService?wsdl";
		$SubClient=new SoapClient($wsdl,array('trace' => 1));

		$SPCredentials=array('email'=>"virgilp@megameeting.com",
			 'password'=>"M3gav1");
		$meetName="Host$alid-".time();
		$mdx=md5($meetName);
		$password=substr($mdx,0,2).substr($mdx,-4);

		/*** Our assigned values. ***/
		// These are from the basic set up by Mary.
		$COS="MegaMeeting";
		$CosID=10;
		$did = ($tollfree)? "8884120872" : "2064020823";
		$WEBSiteAccountNumber=$acctAPC;
		$adminID=3;
		/*** end assigned values ***/
		// bug 2300 - allow a few chances to get codes.
		$chance=0;
		while ($chance<5) {
		// get codes from the database and mark them as used by setting the AL_ID, APConf_ID
			if ($modCode<1) { $apair = $this->getPair($alid, $apcid); }
			if (count($apair)) {
				$modCode = $apair[0];
				$attCode = $apair[1];
			} else {
				$chance++;
				sleep(1);
				continue;
			}
			$ret="$modCode:$attCode";

			$AccessCodeDTOhost = array(
				'accessCode' => $modCode,
				'role' => 'host'
			);
			$AccessCodeDTOpart = array(
				'accessCode' => $attCode,
				'role' => 'part'
			);

			$DidMapDTO=array(
				'did' => $did
			);

			$AccessCodeArray[0]=$AccessCodeDTOhost;
			$AccessCodeArray[1]=$AccessCodeDTOpart;

			/* Populate the DID you want to assign the user to, this can now be
		   an array of many dids.  the example just shows one */

			$DIDArray=array($DidMapDTO);

			$SubscriberDTO = array(
				'accessCodes' => $AccessCodeArray,
				'accountNumber'=>$WEBSiteAccountNumber,
				'active' => true,
				'address1' => '',
				'address2'=> '',
				'administratorId' => $adminID,
				'city' => '',
				'companyName' =>'',
				'conferenceCosId' => $CosID,
				'conferenceId' => $apcid,
				'conferenceName' => $meetName,
				'cosName' => $COS,
				'country' => '',
				'dids' => $DIDArray,
				'email' => $email,
				'emailNotify' => FALSE,
				'firstName' => '',
				'hostPin' => '',
				'lastName' => '',
				'mobilePhone' => '',
				'notes' => '',
				'roleId' => 1,
				'password' => $password,
				'state' => '',
				'username' => $modCode,
				'workPhone' => '',
				'zip' => '1'
			);

			$AddParms=array('in0'=>$SPCredentials, 'in1'=>$SubscriberDTO);
			try {
				$response=$SubClient->addSubscriber($AddParms);
			} catch (SoapFault $exception) {
				// soap_error_handler($exception);
				$this->logSoapErr("reserveAPlusCodesOld()", $trace);
			}

			$res=$response->out->result;
			$msg=$response->out->message;
			// print "CODES: $ret\nRESPONSE:$res\n---\n$msg\n";
			if (strtolower($res) == "success") {
				$out="$res,OK $did $modCode $attCode";
				// log some info
				$codez="Moderator: $modCode, Guest: $attCode";
				$ccc="update APlusConfIDs set APConf_Info='$codez' where APConf_ID='$apcid'";
				mysql_query($ccc, $db);

				// $trace=print_r($response->out, true);
				$trace="reserveAPlusCodesOld() success. Cnf: $apcid, Mod: $modCode, Att: $attCode";
				$this->log2file($this->s_ServiceClass,$trace, "/var/log/httpd/amfphp_debug_log");

				// ok, no need to continue counting attempts
				$chance=5;
			} else {
				// undo the APlus reservations
				$out=$msg;
				$sq1="update APlusCodes set AL_ID='0', APConf_ID='0' ".
				"where APN_Code in ('$modCode','$attCode')";
				mysql_query($sq1, $db);

				$sql = "insert into APlusErrors (APE_Details) ".
				"values ('Conf: $apcid, M: $modCode, A: $attCode - $msg')";
				mysql_query($sq1, $db);

				$trace="reserveAPlusCodesOld() C: $apcid, Mod: $modCode, Att: $attCode - $res [$msg]";
				$this->log2file($this->s_ServiceClass,$trace);
				$modCode=0;
				$attCode=0;
				// update counter - 5 chances to get codes.
				$chance++;

				// burned all the chances, free the APConf_ID
				if ($chance>4) {
					$sq2="update APlusConfIDs set AL_ID='0' where APConf_ID='$apcid'";
					mysql_query($sq2, $db);
				}
			}

		} // end while

		// replicate
		if (strpos($out, "OK ")!==false) {
		}
		return $out;
	}

	private function sendAPlusRequest($zone,$data) {
			$zloc=array(
			"WA"=>"http://4.14.61.36:8080/ccws/services/SubscriberWebService?wsdl",
			"NY"=>"http://4.34.118.190:8080/ccws/services/SubscriberWebService?wsdl"
		);
		$out = "";
		$wsdl=$zloc[$zone];
		$SPCredentials=array('email'=>"virgilp@megameeting.com", 'password'=>"M3gav1");
		$AddParms=array('in0'=>$SPCredentials, 'in1'=>$data);
		try {
			$SubClient=new SoapClient($wsdl,array('trace' => 1, 'connection_timeout' => 2));
			$response=$SubClient->addSubscriber($AddParms);
			$out = $response->out;
		} catch (Exception $exception){
			$this->log2file($this->s_ServiceClass, "sendAPlusRequest() SOAP error. ".$exception->getMessage());
		}

		// $res=$response->out->result;
		// $msg=$response->out->message;
		return $out;
	}

	private function aplusUnified($alid) {
		$db = $this->dbh;
		$out = "";
		$apcid = "";
		$row = array();
		// try the APlusUnified table first.
		$sql = "select APU_ID, APU_ModCode, APU_AttCode from APlusUnified where ".
		"AL_ID=0 && APU_Bridge1=0 && APU_Bridge2=0 order by rand() limit 1";
		$res=mysql_query($sql,$db);
		if ($res) {
			$row=mysql_fetch_array($res, MYSQL_NUM);
			mysql_free_result($res);
		}
		if ($row[0]) {
			$out = implode(":", $row);
			$upd = "update APlusUnified set AL_ID='$alid' where APU_ID='{$row[0]}'";
			mysql_query($upd, $db);
			return $out;
		}

		// Add to APlusUnified based on legacy codes.
		$apcid = $this->reuseAPConf($alid);
		if ($apcid) {
			list ($modCode, $attCode) = $this->getPair($alid, $apcid);
			$ccc="insert into APlusUnified (APU_ID, AL_ID, APU_DateTime, APU_ModCode, APU_AttCode) ".
			"values ('$apcid', '$alid', utc_timestamp(), '$modCode', '$attCode') ";
			// debugging
			/*
			$trace = "aplusUnified() debug. SQL: $ccc";
			$this->log2file($this->s_ServiceClass, $trace);
			*/
			mysql_query($ccc, $db);
			$out = "$apcid:$modCode:$attCode";
		}
		return $out;
	}

	private function reuseAPConf($alid) {
		$db = $this->dbh;
		$out = 0;
		$apcs = "";

		// get a range of possible Conf IDs
		$sql = "select APConf_ID from APlusReleaseLog where APConf_ID!=0 ".
		"order by rand() limit 10";
		$res=mysql_query($sql,$db);
		if ($res) {
			while (($row=mysql_fetch_array($res))!=false) { $apcs.=$row[0].","; }
			mysql_free_result($res);
		}
		$codes = trim($apcs, ",");

		// see if any of the recently released ones have been reused.
		$sql = "select a.APConf_ID, count(APN_Code) as rs from APlusConfIDs a ".
		"left join APlusCodes b on a.APConf_ID=b.APConf_ID where a.APConf_ID ".
		"in ($codes) group by APConf_ID";
		$res=mysql_query($sql,$db);
		if ($res) {
			while (($row=mysql_fetch_array($res))!=false) {
				// take the first available code
				if (!$out && (int)$row[1]<1) { $out = $row[0]; }
			}
			mysql_free_result($res);
		}

		// last resort, just create a new APConf_ID
		if (!$out) {
			$sql = "insert into APlusConfIDs (AL_ID, APConf_Datetime) ".
			"values ('$alid', utc_timestamp())";
			if (mysql_query($sql, $db)) { $out = mysql_insert_id(); }
		}
/* debugging
		$this->log2file($this->s_ServiceClass, "reuseAPConf($alid) returning $out");
*/
		return $out;
	}


	private function reserveTwilio($tcProfile, $phone) {
		$db = $this->dbh;
		$out = "";
		$rscode = uniqid("conf");
		$tpro = mysql_escape_string($tcProfile);
		$sql = "update TWConferenceCodes set TCProfile_ID='$tpro', TWCC_Conference='$rscode' ".
		"where TWCC_Conference='' order by rand() limit 2";
		$ok = mysql_query($sql,$db);
		if (!$ok) { return $out; }

		$affected = mysql_affected_rows($db);
		if ($affected>1) {
			$sql = "select TWCC_ID from TWConferenceCodes where TWCC_Conference='$rscode'";
			$res=mysql_query($sql,$db);
			if ($res) {
				$att = "";
				$mod = "";
				while (($row=mysql_fetch_array($res))!=false) {
					if (!$att) { $att = $row[0]; }
					else { $mod = $row[0]; }
				}
				mysql_free_result($res);
// $res,OK $did $modCode $attCode
				$out = "twilio,OK $phone $mod $att";
				// setting moderator code
				$doMod = "update TWConferenceCodes set TWCC_IsMod=1 where TWCC_ID='$mod'";
				mysql_query($doMod, $db);
			}
		} else {
			$sql = "update TWConferenceCodes set TWCC_IsMod=0, TCProfile_ID=0, TWCC_Conference='' ".
			"where TWCC_Conference='$rscode'";
			mysql_query($sql,$db);
		}
		return $out;
	}

	private function releaseTwilio($confCode) {
		$db = $this->dbh;
		$out = false;
		$code = mysql_escape_string($confCode);
		$conf = "";
		$sql = "select TWCC_Conference from TWConferenceCodes where TWCC_ID='$code'";
		$res=mysql_query($sql,$db);
		if ($res) {
			list ($conf) =mysql_fetch_array($res);
			mysql_free_result($res);
		}
		if (!$conf) { return $out; }

		$sql = "update TWConferenceCodes set TWCC_IsMod=0, TCProfile_ID=0, TWCC_Conference='' ".
		"where TWCC_Conference='$conf'";
		$out = mysql_query($sql,$db);
		if (!$out) {
			$trace = "releaseTwilio() error. SQL: $sql\n".mysql_error();
			$this->log2file($this->s_ServiceClass,$trace);
		}
		return $out;
	}

	private function procFCObj($alid, $obj) {
		$db=$this->dbh;
		/*
		id:int
		phone:string
		mod:string
		att:string
		*/
		$mod=$obj["mod"];
		$fcid=$obj["id"];
		$att=$obj["att"];
		$phn=$obj["phone"];
		$out="OK $phn $mod $att";
		$sql="insert into FreeConferenceIDs (AL_ID, FreeConf_ModCode, FreeConf_ID) ".
		"values ('$alid', '$mod', '$fcid')";
		if (!mysql_query($sql,$db)) {
			$log="procFCObj() - Data not committed. SQL: $sql";
			$this->log2file($this->s_ServiceClass, $log);
		}
		return $out;
	}

	/**
	* Process teleconferencing change.
	* When a meeting's teleconference info is updated, we must make sure
	* that existing codes get released.
	*
	* @param Object Meeting update object.
	*/
	private function changeTC($info) {
		$db = $this->dbh;
		$chg=0;
		$mid = $info['Meet_ID'];
		$tcphn = (isset($info['Meet_CallInNumber']))? $info['Meet_CallInNumber']:"-NA-";
		$tcmod = (isset($info['Meet_ModeratorCode']))? $info['Meet_ModeratorCode']:"-NA-";
		$tcatt = (isset($info['Meet_AttendeeCode']))? $info['Meet_AttendeeCode']:"-NA-";
		// get existing telconference numbers
		/*
		$sql="select Meet_CallInNumber, Meet_ModeratorCode, Meet_AttendeeCode, TCProfile_Url,".
		", TCProfile_User, TCProfile_Pass from Meetings a, AccountLogins b, TeleconferenceProfiles c".
		"where a.AL_ID=b.AL_ID && AL_TCTollProfile=TCProfle_ID && Meet_ID='$mid'";

		// arrrrggghhh - AL_TCTollProfile might change, so release needs to be based
		on existing phone number.
		*/
		$sql="select Meet_CallInNumber, Meet_ModeratorCode, Meet_AttendeeCode, AL_ID ".
		"from Meetings where Meet_ID='$mid'";
		$res=mysql_query($sql,$db);
		$phone="";
		if ($res) {
			$row=mysql_fetch_assoc($res);
			mysql_free_result($res);
			$phone=$row['Meet_CallInNumber'];
			$modcode=$row['Meet_ModeratorCode'];
			$attcode=$row['Meet_AttendeeCode'];
			$alid=$row['AL_ID'];
		}
		// confirm change
		if ($tcphn != "-NA-" && $tcphn != $row['Meet_CallInNumber']) { $chg=1; }
		if ($tcmod != "-NA-" && $tcmod != $row['Meet_ModeratorCode']) { $chg=1; }
		if ($tcatt != "-NA-" && $tcatt != $row['Meet_AttendeeCode']) { $chg=1; }

		// $trace="changeTC() P:$tcphn, M:$tcmod, A:$tcatt. ChangeFlag: $chg";
		// $this->log2file($this->s_ServiceClass, $trace, $this->debugLog);
		if ($chg) {
			$del = $this->deleteCodes($phone, $modcode, $attcode, 'US');
			if (!$del) {
				$trace = "changeTC() No changes.";
				$this->log2file($this->s_ServiceClass, $trace);
			}
		}
	}

	/**
	* Get APlus codes for use in M2, GJI.
	* Fix for bug 2177. Allow MM2 and GJI to use APlus numbers.
	* This method will be called by an external script.
	* The output should look like:
	* <code>OK 2064020823 [modCode] [attCode]</code>
	*
	* @return String.
	*/
	function aplusForOthers() {
		// Bug 3947 - Need to instantiate a Teleconference object before
		// calling reserveAPlusCodes()
		$parseThis = "";

		if (!$this->telecon) { $this->telecon = new Teleconference(); }

		// handle testcases, if defined. This allows us to test results
		// without actually committing APlus codes.
		if (defined("TESTCASE")) {
			switch (TESTCASE) {
				case 1: $parseThis="Success,OK 5551112222 27445555 27441111"; break;
				case 2: $parseThis="Error. Duplicate username."; break;
				case 3: $parseThis = $this->reserveAPlusCodes(612, 0, "1A", "", "WA"); break;
				case 4: $parseThis = $this->reserveAPlusCodes(612, 0, "1A", "", "NY"); break;
				default: $parseThis="UNHANDLED TEST CASE.";
			}
		} else {
			// Use AL_ID 612 for this workflow.
			// 612 is a non-active account login from originla01.megameeting.com
			// $parseThis = $this->reserveAPlusCodes(612);
			$parseThis = $this->reserveAPlusCodes(612, 0, "1A", "", "NY");
		}

		return $parseThis;
	}

	/**
	* Release aplus code.
	* This performs the inverse of aplusForOthers. The specified codes
	* are freed rather than reserved. This function is for use by
	* products outside of m3.
	*
	* @param String Phone number.
	* @param String Moderator code.
	* @param String Attendee code.
	* @return String Status message.
	*/
	function aplusRelease($phone, $mod, $att) {
		if (defined("LOGDEBUG")) {
			$trace="aplusRelease() debug - $phone, M: $mod, A: $att";
			$this->log2file($this->s_ServiceClass, $trace);
		}
		if (!$phone) { $err="Invalid phone number."; }
		else if (!$mod) { $err="Invalid moderator code."; }
		else if (!$att) { $err="Invalid attendee code"; }
		else {
			if (defined("TESTCASE")) {
				$err="SUCCESS";
			} else {
				$free = $this->releaseAPlusCodes($mod, $att);
				$err=($free)? "SUCCESS": "OK";
			}
		}

		return $err;
	}

	private function releaseAPlusCodes($mod, $att) {
		$db=$this->dbh;
		$apconf = "";
		$out = false;
		$b1 = 1;    // bridge statuses... statii?... statum?
		$b2 = 1;
		$mc = mysql_escape_string($mod);
		$sql = "select APConf_ID from APlusCodes where APN_Code='$mc'";
		$res = mysql_query($sql, $db);
		if ($res) {
			list ($apconf) = mysql_fetch_array($res);
			mysql_free_result($res);
		}
		if (!$apconf) {
			$sql = "select APU_ID from APlusUnified where APU_Modcode='$mc'";
			$res = mysql_query($sql, $db);
			if ($res) {
				list ($apconf) = mysql_fetch_array($res);
				mysql_free_result($res);
			}
			if (!$apconf) { return $out; }
		}

		/* APlusUnified has some duplicate codes. If this is one of them, just
		delete the record. This code can be removed after there are no more
		results for this query:
		select a.APU_ID as id_1, b.APU_ID as id_2 from APlusUnified a, APlusUnified b
		where a.APU_ModCode=b.APU_AttCode || a.APU_AttCode=b.APU_ModCode;
		*/
		$qqq = "select count(*) from APlusUnified where APU_ModCode in ($mod, $att)";
		$res = mysql_query($qqq, $db);
		if ($res) {
			list ($apct) = mysql_fetch_array($res);
			mysql_free_result($res);
		}
		// If apct>1 the update query will become a delete query


		// Prep update for APlusUnified
		$upd = "update APlusUnified set AL_ID=0";

		// now send SOAP request (WA)
		$wsdl="http://4.14.61.36:8080/ccws/services/SubscriberWebService?wsdl";
		$SubClient=new SoapClient($wsdl,array('trace' => 1));
		$SPCredentials=array('email'=>'virgilp@megameeting.com', 'password'=>'M3gav1');

		$AddParms=array('in0'=>$SPCredentials, 'in1'=>$mod);
		try {
			$response=$SubClient->deleteByUsername($AddParms);
			$res=$response->out->result;
			$msg=$response->out->message;
			if (strtolower($res) == "success") {
				$b1=0;
				$upd.=", APU_Bridge1=0 ";
			} else {
				$trace="releaseAPlusCodes() WA error. $msg";
				$this->log2file($this->s_ServiceClass,$trace);
			}
		} catch (SoapFault $exception) {
			// soap_error_handler($exception);
			$this->logSoapErr("releaseAPlusCodes()",$exception);
		}

		// NY bridge too.
		$wsdl="http://4.34.118.190:8080/ccws/services/SubscriberWebService?wsdl";
		$SubClient=new SoapClient($wsdl,array('trace' => 1));
		$SPCredentials=array('email'=>'virgilp@megameeting.com', 'password'=>'M3gav1');

		$AddParms=array('in0'=>$SPCredentials, 'in1'=>$mod);
		try {
			$response=$SubClient->deleteByUsername($AddParms);
			$res=$response->out->result;
			$msg=$response->out->message;
			if (strtolower($res) == "success") {
				$b2=0;
				$upd .= ", APU_Bridge2=0 ";
			} else {
				$trace="releaseAPlusCodes() NY error. $msg";
				$this->log2file($this->s_ServiceClass,$trace);
			}
		} catch (SoapFault $exception) {
			// soap_error_handler($exception);
			$this->logSoapErr("releaseAPlusCodes()",$exception);
		}

		$out = (($b1 + $b2)>0)? false : true;
		if (!$out) {
			$trace="releaseAPlusCodes() mode $mode Bridge warning. B1=$b1, B2=$b2";
			$this->log2file($this->s_ServiceClass,$trace);
		}

		// update APlusUnified
		if ($apct>1) { $upd = "delete from APlusUnified "; }
		$upd .= "where APU_ID='$apconf'";
		mysql_query($upd, $db);

		// update legacy tables
		$sql="update APlusCodes set AL_ID='0', APConf_ID='0' where APConf_ID='$apconf'";
		if (!mysql_query($sql,$db)) {
			$trace="releaseAPlusCodes() update error. ".mysql_error();
			$this->log2file($this->s_ServiceClass,$trace);
			// return "Error updating DB.";
		}
		$clr = "update APlusConfIDs set AL_ID=0, APConf_Info='' where APConf_ID='$apconf'";
		if (!mysql_query($clr,$db)) {
			$trace="releaseAPlusCodes() update error. ".mysql_error();
			$this->log2file($this->s_ServiceClass,$trace);
		}
		$nyx = "update APlusNY set AL_ID=0 where AP_ConfID='$apconf'";
		mysql_query($nyx, $db);
		return $out;
	}

	private function releaseFreeConf($alid, $mod) {
		$db=$this->dbh;
		$ok="ERROR";
		$mc=mysql_escape_string($mod);
		$wclause = "where AL_ID='$alid' && FreeConf_ModCode='$mc'";
		$sql="select FreeConf_ID from FreeConferenceIDs $wclause";

		// $trace="releaseFreeConf() debug. SQL: $sql";
		// $this->log2file($this->s_ServiceClass,$trace, $this->debugLog);

		$res=mysql_query($sql,$db);
		if ($res) {
			list($fcid)=mysql_fetch_array($res);
			mysql_free_result($res);
		}
		if ($fcid) {
			$tcObj = new Teleconference();
			// $trace="releaseFreeConf() debug. Calling TC.deleteFreeConfCode($fcid)";
			// $this->log2file($this->s_ServiceClass,$trace, $this->debugLog);
			$ok = $tcObj->deleteFreeConfCode($fcid);
			if (strpos($ok,"ERROR")===false) {
				$ddd="delete from FreeConferenceIDs $wclause";
				mysql_query($ddd,$db);
			}
		}
		return $ok;
	}

	/**
	* Update teleconference info.
	* For billing and reporting purposes, it may be necessary to store info
	* regarding a specific teleconference. This method provides a means to
	* update as necessary. The parameter must be an object that contains
	* a 'Meet_ID' property, and at least one of the supported properties
	* identified in this list:<code>
	* Meet_ID - Valid meeting ID.
	* special - Customer defined data.
	* status - R=remove from bridge, E=enable on bridge.
	* name - Conference name.
	* firstname - First name of conference host.
	* lastname - Last name of conference host.
	* desc - Description.
	* email1 - Email address where notifications are sent.
	* tones - 0=none, 1=entry, 2=exit, 3 (default) =both
	* mode - P=presentation, C=conversation, Q=Q and A
	* </code>
	*
	* Omitted properties will not change.
	*
	* @param Object See description.
	* @return String API response.
	*/
	function updateTCInfo($o_Data) {
		$db=$this->dbh;
		$out="";
		if (!isset($o_Data['Meet_ID'])) { return "ERROR. Invalid meeting ID."; }
		if (count($o_Data)<2) { return "ERROR. Insufficient fields."; }
		// get existing data
		$mid=$o_Data['Meet_ID'];
		$sql="select Meet_CallInNumber, Meet_ModeratorCode, Meet_AttendeeCode, ".
		"AL_TCProfile, Acc_Region from Meetings a, Accounts b, AccountLogins c ".
		"where a.Acc_ID=b.Acc_ID && a.AL_ID=c.AL_ID && Meet_ID=$mid";
		$res=mysql_query($sql,$db);
		if ($res) {
			list ($tcnum, $tcmod, $tcatt, $tcpro, $rgn)=mysql_fetch_array($res);
			mysql_free_result($res);
		}
		// Bail if teleconference info does not exist.
		if (!isset($tcnum) || !$tcmod || !$tcatt) {
			return "ERROR. Can not determine teleconference info.";
		}
		if ($tcpro<1) { $tcpro=($rgn=='UK')?2:1; }  // Use default profiles if necessary

		// load profile
		$sql="select * from TeleconferenceProfiles where TCProfile_ID='$tcpro'";
		$res=mysql_query($sql,$db);
		if ($res) {
			$tp=mysql_fetch_assoc($res);
			mysql_free_result($res);
		}

		$tcObj=new Teleconference();
		$tcObj->buildConnector($tp['TCProfile_Url'],$tp['TCProfile_User'],$tp['TCProfile_Pass']);
		// Build query - usr and pass params are already set
		$qry="cmd=update&mod=$tcmod&att=$tcatt";

		// add desired parameters
		foreach ($o_Data as $k=>$v) {
			if ($k=="Meet_ID") { continue; }
			$tmp=urlencode($v);
			$tmp=str_replace('+','%20',$tmp);
			$qry.="&$k=$tmp";
		}
		$out=$tcObj->sendRequest($qry);
		return $out;
	}

	/**
	* Release teleconferencing codes.
	* Deactivates a set of teleconferencing codes associated to a meeting.
	* This assumes that the call-in number, moderator code, and attendee code
	* were generated by this API to begin with.
	* @param int Meeting ID.
	* @return boolean
	*/
	function cleanTCCodes($mtg) {
		$db = $this->dbh;
		$out=false;
		/* modified 9 Oct 2015 - made deleteCodes() a little smarter so we don't really
		need a complex query here.
		$sql=<<<END_QUERY
select Meet_CallInNumber, Meet_AttendeeCode, Meet_ModeratorCode, AL_TCProfile,
Acc_EnablePrivateBranded, a.AL_ID from Meetings a, AccountLogins b, Accounts c,
where a.AL_ID=b.AL_ID && b.Acc_ID=c.Acc_ID && Meet_ID=$mtg
END_QUERY;
*/
		$meetID=mysql_escape_string($mtg);
		$sql="select Meet_CallInNumber, Meet_AttendeeCode, Meet_ModeratorCode from Meetings ".
		"where Meet_ID='$meetID'";

		$res=mysql_query($sql,$db);
		if ($res) {
			$row=mysql_fetch_assoc($res);
			mysql_free_result($res);
		}
		$phone=$row['Meet_CallInNumber'];
		$modcode=$row['Meet_ModeratorCode'];
		$attcode=$row['Meet_AttendeeCode'];

		$proceed = $this->deleteCodes($phone, $modcode, $attcode, 'nomatter');
		if ($proceed) {
			$sql="update Meetings set Meet_CallInNumber='', Meet_ModeratorCode='', ".
			"Meet_AttendeeCode='' where Meet_ID='$mtg'";
			$out = mysql_query($sql, $db);
		}

		/*
		// load the profile
		$sql="select * from TeleconferenceProfiles where TCProfile_ID=$tpro";
		$res=mysql_query($sql,$db);
		if ($res) {
			$tp=mysql_fetch_assoc($res);
			mysql_free_result($res);
		}
		$tcObj=new Teleconference();
		$tcpurl = $tp['TCProfile_Url'];
		$tcObj->buildConnector($tcpurl,$tp['TCProfile_User'],$tp['TCProfile_Pass']);

		if ($tcpurl == "aplus") { $rsp=$this->releaseAPlusCodes($modcode, $attcode); }
		elseif ($tcpurl == "freeConf") { $rsp=$this->releaseFreeConf($alid, $modcode); }
		elseif (strpos($tcpurl, "twilio")!==false) { $rsp=$this->releaseTwilio($modcode); }
		else { $rsp=$tcObj->deleteCode($phone, $modcode, $attcode); }

		if (strpos($rsp,"ERROR")!==false) {
			$this->log2file($this->s_ServiceClass,"cleanTCCodes() error. $rsp");
			$out=false;
		}
		*/
		return $out;
	}

	/**
	* Get a number for teleconferencing.
	* Requires a teleconference type indicator and a region. The type indicator can
	* be <i>public</i> or <i>private</i>. Use <i>private</i> only if the account is private-branded.
	* The region may be <i>USA</i> or <i>UK</i>. The returned object will have the following signature:
	* <code>{
	* phonenumber: [string of digits],
	* modcode: [string of digits],
	* attcode: [string of digits]
	* }</code>
	* @param String Teleconference type indicator.
	* @param String Region identifier.
	* @return Object
	*/
	function createCodes($pubPriv,$region) {
		$this->telecon=new Teleconference();
		$out=array();
		$ok=false;
		$type=strtolower($pubPriv);
		$ctry=strtoupper($region);

		// get phone number
		$phn=$this->tollNumber($type,$ctry);

		// create codes
		$qs="cmd=create&phone=$phn";
		$raw=$this->telecon->callAPI($qs,$ctry);
//      $this->log2file($this->s_ServiceClass,"createCodes() debug. $raw","/var/log/httpd/amfphp_debug_log");
		/*
		UK: RESPONSE,OK 08448482494 4406845 29678
		USA: RESPONSE,OK 7123388661 2655671 2655671
		*/
		$out=$this->parseCodes($raw);
		$ok=isset($out['modcode']);

		// log if for some reason we get unexpected results
		if (!$ok) {
			$this->log2file($this->s_ServiceClass,"createCodes() - Unexpected results: $raw");
		}
		return $out;
	}

	private function parseCodes($data) {
		$out=array('phonenumber'=>'error');
		$buf=explode("\n",$data);
		foreach ($buf as $line) {
			$line=str_replace("\r",'',$line);
			if ($line=="RESPONSE") { continue; }
			else if (strpos($line,"OK")!==false) {
				list($st,$phone,$mod,$att,$tcProfileID)=explode(" ",$line);
				$out['phonenumber']=$phone;
				$out['modcode']=$mod;
				$out['attcode']=$att;
				$out['TCProfile_ID']=$tcProfileID;
			}
		 }
		return $out;
	}

	private function tollNumber($pub,$region) {
		$db=$this->dbh;
		$out='';
		// 27 Apr 2015 - Virgil S. Palitang
		// This query has been broken for a long time. Fixed 'where' clause.
		$sql="select TN_Number,datediff(now(),TN_Timestamp) as age ".
		"from TollNumbers where TN_Region='$region' && TN_Type='$pub'";
		$res=mysql_query($sql,$db);
		if ($res) {
			$row=mysql_fetch_array($res);
			$out=$row[0];
			mysql_free_result($res);
		}

		// if no results, or the number is more than  24 hrs old, get new
		if ($out=='' || $row[1]) {
			$sql="delete from TollNumbers where TN_Region='$region'";
			if (!mysql_query($sql,$db)) {
				$this->log2file($this->s_ServiceClass,"tollNumber() - delete failed. SQL: $sql");
			} else {
				// getting the new numbers, and populating the database
				$tc=$this->telecon;
				$raw=$tc->callAPI("cmd=numbers&flag=G",$region);
				// $this->log2file($this->s_ServiceClass,"tollNumber() debug: $raw");
				if ($region=='UK') { $props=$tc->parseUK($raw); }
				else { $props=$tc->parseUS($raw); }

				if ($pub=='public') { $out=$props['public']; }
				else { $out=$props['private']; }

				$sql="insert into TollNumbers (TN_Region,TN_Number,TN_Type) values ".
				"('$region','".$props['public']."','public'),".
				"('$region','".$props['private']."','private')";
				// update the new tables too, even though they are not being utilized here.
				if ($region=="UK") {
					$qqq="insert into TeleconferenceNumbers (TCProfile_ID, TCNumber_Data, TCNumber_Group) values ".
					"('2','".$props['public']."','public'),".
					"('2','".$props['private']."','private')";
				} else {
					$qqq="insert into TeleconferenceNumbers (TCProfile_ID, TCNumber_Data, TCNumber_Group) values ".
					"('1','".$props['public']."','public'),".
					"('1','".$props['private']."','private')";
				}
				mysql_query($qqq,$db);

				if (!mysql_query($sql,$db)) {
					$this->log2file($this->s_ServiceClass,"tollNumber() - insert failed. SQL: $sql");
				}
			}
		}
		//
		return $out;
	}

	/**
	* Cleanup teleconferencing codes.
	* Call this function when ending/disabling a meeting.
	* Requires phone number, moderator code, attendee code, region.
	* Returns true on success, false otherwise.
	* @param String
	* @param String
	* @param String
	* @param String
	* @return Boolean
	*/
	function deleteCodes($phone,$modcode,$attcode,$rgn="") {
		$db = $this->dbh;
		$ok = false;
		$tp_Url = "";
		$region="USA";
		$phDigits = preg_replace('/\D/','', $phone);
		// don't need really need region anymore. We can figure it
		// out by the phone/TCProfile_Url
		$sql = "select distinct(TCProfile_Url) from ".
		"TeleconferenceProfiles a, TeleconferenceNumbers b ".
		"where a.TCProfile_ID=b.TCProfile_ID && TCNumber_Data in ('$phone', '$phDigits')";
		if(defined("LOGDEBUG")) {
			$trace = "deleteCodes() debug. SQL: $sql\n";
			$this->log2file($this->s_ServiceClass,$trace);
		}
		$res=mysql_query($sql,$db);
		if ($res) {
			list($tp_Url)=mysql_fetch_array($res);
			if (strpos($tp_Url,"egenie")!==false) { $region="UK"; }
			mysql_free_result($res);
		}
		if (!$tp_Url) { return $ok; }
		if(defined("LOGDEBUG")) {
			$trace = "deleteCodes() debug. $phone -> $tp_Url\n";
			$this->log2file($this->s_ServiceClass,$trace);
		}

		// As per Bug 3913, both WA and NY numbers will invoke the same
		// code since they should be replicated.
		if ($tp_Url == "aplus") {
			$tstat = "APlus";
			$ok = $this->releaseAPlusCodes($modcode, $attcode);
		} elseif ($tp_Url == "aplusny") {
			/*
			$this->telecon=new Teleconference();
			$this->telecon->setDBHandle($this->dbh);
			$tstat = "APlusNY";
			$ok = $this->telecon->releaseAPlusNYCodes($modcode, $attcode);
			*/
			$tstat = "APlusNY";
			$ok = $this->releaseAPlusCodes($modcode, $attcode);
		} elseif ($tp_Url == "freeConf") {
			$tstat = "freeconference";
			$rsp=$this->releaseFreeConf($alid, $modcode);
			if (strpos($rsp,"ERROR")===false) { $ok = true; }
		} elseif (strpos($tp_Url,"twilio")!==false) {
			$tstat = "twilio";
			$ok=$this->releaseTwilio($modcode);
		} else {
			$tstat = "secureconf/conferencegenie";
			$this->telecon=new Teleconference();
			$qry="cmd=delete&phone=$phone&mod=$modcode&att=$attcode";
			$err=$this->telecon->callAPI($qry,$region);
			if (strpos($err,"ERROR")===false) { $ok=true; }
			if (!$ok) {
				$errstr="deleteCodes() - Error: $err";
				$this->log2file($this->s_ServiceClass,$errstr);
			}
		}
		$trace = "deleteCodes() Releasing from $tstat [$phone, $modcode, $attcode].";
		$this->log2file($this->s_ServiceClass, $trace);
		return $ok;
	}

	// - - - - - Guest message management - - - - -
	/**
	* Delete a guest message.
	* Part of the MeetUsNow functionality.
	* Guest messages can be created when a host misses or denies a guest entry.
	*
	* @param int Message ID.
	* @return Boolean True on success, False otherwise.
	*/
	function deleteGuestMessage($n_MsgID) {
		$db=$this->dbh;
		$msg=mysql_escape_string($n_MsgID);
		$sql="delete from GuestMessages where GMsg_ID='$msg'";
		$out=mysql_query($sql,$db);
		if (!$out) {
			$trace="deleteGuestMessage() error. SQL: $sql\n".mysql_error();
			$this->log2file($this->s_ServiceClass, $trace);
		}
		return $out;
	}


	// - - - - - Uploaded image management - - - - -

	/**
	* Get image list.
	* Get previously uploaded images. The results are limited to images associated
	* to the specified account login. Each element in the result set is an object
	* having the following properties:
	* <ul>
	* <li>UI_ImageID:String - A unique image identifier.</li>
	* <li>UI_ImageFile:String - The full path to the image.</li>
	* <li>UI_ImageSize:int - The image size in bytes.</li>
	* <li>UI_ImageServer:String - The server where the image is located.</li>
	* <li>UI_ImageWidth:int - The pixel width of the image.</li>
	* <li>UI_ImageHeight:int - The pixel height of the image.</li>
	* </ul>
	* @param int Account Login ID
	* @return Array List of UserImage records.
	*/
	function getImages($alid) {
		$db=$this->dbx;
		$out=array();
		$sql="select UI_ImageID, UI_ImageFile, UI_ImageSize, UI_ImageServer,".
		"UI_ImageWidth,UI_ImageHeight from UserImages a, Meetings b ".
		"where a.Meet_ID=b.Meet_ID && AL_ID=$alid";
		$res=mysql_query($sql,$db);
		if ($res) {
			while (($row=mysql_fetch_assoc($res))!=false) { $out[]=$row; }
			mysql_free_result($res);
		}
		return $out;
	}

	/**
	* Delete uploaded image.
	* Only user-uploaded images can be deleted.
	* @param String Image ID.
	* @return Boolean
	*/
	function deleteImage($imgID) {
		$db=$this->dbh;
		$out=true;
		$srv="";
		$img="";
		// get image server info
		$sql="select UI_ImageServer,UI_ImageFile,UI_ThumbFile from UserImages where UI_ImageID='$imgID'";
		$res=mysql_query($sql,$db);
		if ($res) {
			list($srv,$img,$thm)=mysql_fetch_array($res);
			mysql_free_result($res);
		}

		// remove from server
		if ($srv) {
			$sql="delete from UserImages where UI_ImageID='$imgID'";
			$ok=mysql_query($sql,$db);

			$delThis=urlencode($img);
			$andThis=urlencode($thm);
			$ch=curl_init();
			curl_setopt($ch,CURLOPT_URL, "http://$srv/delImg.php?id=$delThis&thm=$andThis");
			curl_setopt($ch,CURLOPT_RETURNTRANSFER, true);
			$res=curl_exec($ch);
			curl_close($ch);
			if (!$res) { $ok=false; }
		}
		return $out;
	}

	/**
	* Get a profile by ID.
	* Returns a settings profile.
	* @param int Profile ID.
	* @return Object A settings profile record.
	*/
	function getProfile($n_Profile) {
		$db=$this->dbx;
		$out=array();
		$sql="select * from AccountSettingProfiles where Acc_ID=$n_Acct && Asp_ID='$n_Profile'";
		$res=mysql_query($sql,$db);
		if ($res) {
			$out=mysql_fetch_assoc($res);
			mysql_free_result($res);
		}
		return $out;
	}

	/**
	* Anonymize meeting usage history.
	* Initial intent was to clear records from the MeetingUsage table. Now, we
	* are just updating the record to obfuscate personal info. The fields
	* MU_User, MU_Email, and MU_Phone will be updated to something that cannot
	* be specfically associated to anyone. Requires meeting ID. If used
	* in the API context, then an Account ID is also required. Otherwise, the
	* account will be automatically retrieved from the amfphp session.
	*
	* @param int Meeting ID.
	* @param int Account ID. Only required outside of AMFPHP.
	* @result boolean True on success.
	*/
	function clrMeetingHistory($meetID, $account=0) {
		$db = $this->dbh;
		$mtg = mysql_escape_string($meetID);
		$acct = mysql_escape_string($account);
		$out = false;
		if (!defined("NO_SESSIONS")) { $acct=$_SESSION['acct']; }
		$ac= "Acc_ID='$acct'";

		$sql = "update MeetingUsage a, Meetings b set ".
		"MU_User=concat('anon-', substr(md5(concat(MU_User, ':', MU_IP)),1,4), ".
		"substr(md5(concat(MU_User, ':', MU_IP)),-4) ), MU_IP='x.x.x.x', ".
		"MU_Email='', MU_Phone='' where a.Meet_ID=b.Meet_ID && ".
		"MU_IP!='x.x.x.x' && a.Meet_ID='$mtg' && Acc_ID='$acct'";
		$out = mysql_query($sql, $db);
		if (!$out) {
			$err = "clrMeetingHistory() failed. ";
			$this->log2file($this->s_ServiceClass,"clrMeetingHistory() failed. SQL: $sql");
			if (defined("THROW_ERRORS")) { throw new Exception($err); }
		}
		return $out;
	}

/* - - - - - begin Registration style support functions - - - - - */

	function getMeetingRegLogoID($n_ALID, $n_MeetID) {
		$db=$this->dbx;
		$hostID = mysql_escape_string($n_ALID);
		$meetID = mysql_escape_string($n_MeetID);
		$out=0;
		$sql="select if(Logo_ID, Logo_ID, 0) as Logo_ID, Meet_RegStyleID, Acc_EnablePrivateBranded ".
		"from Accounts c, Meetings a left join RegPageStyle ".
		"on Meet_RegStyleID=RPS_ID where a.Acc_ID=c.Acc_ID && a.AL_ID='$hostID' ".
		"&& a.Meet_ID='$meetID'";
		$res=mysql_query($sql,$db);
		if ($res) {
			$row=mysql_fetch_array($res);
			mysql_free_result($res);
			if ($row['Meet_RegStyleID'] < 1) {
				$isPb = $row['Acc_EnablePrivateBranded'];
				$out = ($isPb) ? 0 : 1;
			} else {
				$out = $row['Logo_ID'];
			}
		}
		return $out;
	}
	/**
	* Get list of available registration page logos.
	* Returns a list of logo objects. Each object in the array follows the structure:<code>
	* Logo_ID:int - Numeric ID.
	* Logo_Name:String - Image nickname.
	* Logo_RelativeURL:String - Server-relative path to the image.
	* </code>
	*
	* @param int Account ID.
	* @param int AccountLogin ID.
	* @return Array List of logo objects.
	*/
	function getRegLogos($acct, $alid) {
		$db=$this->dbh;
		$out=array();
		// set up private branded filter
		$sql="select Acc_EnablePrivateBranded from Accounts where Acc_ID='$acct'";
		$res=mysql_query($sql,$db);
		if ($res) {
			$row=mysql_fetch_assoc($res);
			mysql_free_result($res);
		}

		$sql="select Logo_ID, Logo_Name, Logo_RelativeURL from Logos where AL_ID='$alid' || ".
		"(Acc_ID='$acct' && AL_ID=0)";
		if ($row['Acc_EnablePrivateBranded'] < 1) {
			$sql.=" || Logo_ID=1";
		}
		$res=mysql_query($sql,$db);
		if ($res) {
			while (($row=mysql_fetch_assoc($res))!=false) {
				$out[]=$row;
			}
			mysql_free_result($res);
		}
		return $out;
	}

/* - - - - - end Registration style support functions - - - - - */

/* - - - - - 2 Mar 2011 - new deposition mod support functions - - - - - */

	/**
	* New deposition case.
	* Create a new deposition case and get the case id.
	* @param int Account login ID.
	* @param String Case name.
	* @return int New case ID.
	*/
	function createDepoCase($alid,$casename) {
		$db=$this->dbh;
		$out=0;
		$cn=mysql_escape_string($casename);
		$sql="insert into DepoCases (AL_ID,Case_Name) values ($alid,'$cn')";
		if (mysql_query($sql,$db)) {
			$out=mysql_insert_id($db);
		} else {
			$err="createDepoCase() failed. SQL: $sql\n".mysql_error();
			$this->log2file($this->s_ServiceClass,$err);
			if (defined("THROW_ERRORS")) { throw new Exception($err); }
		}
		return $out;
	}

	/**
	* Get list of cases.
	* Get a list of deposition cases associated to a login ID. The returned list consists
	* of objects that have the structure:<code>
	* label:String - The case name.
	* data:int - The case ID.
	* </code>
	*
	* @param int Account login ID.
	* @return Array list of objects.
	*/
	function getDepoCases($alid) {
		$db=$this->dbx;
		$out=array();
		$sql="select Case_ID as data , Case_Name as label from DepoCases where AL_ID='$alid' && Case_Enabled=1";
		$res=mysql_query($sql,$db);
		if ($res) {
			while(($row=mysql_fetch_assoc($res))!==false) { $out[]=$row; }
			mysql_free_result($res);
		}
		return $out;
	}

	/**
	* Added 07/14/11 by JC
	* Requires Case_ID property
	* Function updates Depo Case record, based on an existing Case_ID
	*/
	function updateDepoCase($a_DepoCase) {
		$db=$this->dbh;
		$b_Out=false;
		if (!array_key_exists("Case_ID",$a_DepoCase)) {
			$s_Data="updateDepoCase() failed. Required field Case_ID missing.\n";
			$s_Data.=print_r($a_DepoCase,true);
			$this->log2file($this->s_ServiceClass,$s_Data);
			return $b_Out;
		}
		//If the Case_ID exists, build the update query
		$s_UpdString='';
		$s_WhereClause='where';
		foreach ($a_DepoCase as $key=>$val) {
			if ($key=="Case_ID") {
				$s_WhereClause.=" $key=$val";
			} else {
				if ($s_UpdString) { $s_UpdString.=","; }
				$s_Tmp=mysql_real_escape_string($val,$db);
				$s_UpdString.="$key='$s_Tmp'";
			}
		}
		$s_Sql="update DepoCases set $s_UpdString $s_WhereClause";
		//run ze query
		if (mysql_query($s_Sql,$db)) {
			$this->log2file($this->s_ServiceClass,"updateDepoCase() successful. SQL: $s_Sql\n");
			$b_Out=true;
		} else {
			$out="updateDepoCase() failed. SQL: $s_Sql\n".mysql_error($db);
		}
		return $b_Out;
	}

	/**
	* Create a chat group.
	* Create a chat group for a deposition. Requires an object that has the properties:<code>
	* Meet_ID:int - Required. The deposition that this chat group belongs to.
	* DCG_Name:String - Required. Name of the chat group.
	* DCG_Password:String - Optional. Group password.
	* DCG_Emails:String - Deprecated. Comma-separated list of emails.
	* DCG_Users:Array - List of user objects. See detail below.
	* DCG_HasVOIP:int - Optional. 1=VOIP allowed for this group.
	* DCG_HasVideo:int - Optional. 1=Video allowed for this group.
	* DCG_HasGroupChat:int - Optional. 1=group chat enabled.
	* DCG_HasPrivateChat:int - Optional. 1=private chat enabled.
	* DCG_ReceiveSteno:int - Optional. 1=receives steno updates.
	* DCG_ReceiveAudio:int - Optional. 1=receives audio.
	* DCG_ReceiveVideo:int - Optional. 1=receives video.
	* DCG_ReceiveRaw:int - Optional. 1=receives raw steno.
	* DCG_CanUpload:int - Optional. 1=File uploading is allowed.
	* DCG_CanDownload:int - Optional. 1=File downloading is allowed.
	* DCG_CanExhibit:int - Optional. 1=Can create and control an exhibit inside a deposition.
	* </code>
	*
	* User objects should follow the structure:<code>
	* Invite_Email:String - Email address.
	* Invite_Name:String - User name.
	* Invite_HasVOIP:int - Optional. 1=VOIP allowed for this user.
	* Invite_HasVideo:int - Optional. 1=Video allowed for this user.
	* Invite_HasGroupChat:int - Optional. 1=group chat enabled.
	* Invite_HasPrivateChat:int - Optional. 1=private chat enabled.
	* Invite_ReceiveSteno:int - Optional. 1=receives steno updates.
	* Invite_ReceiveAudio:int - Optional. 1=receives audio.
	* Invite_ReceiveVideo:int - Optional. 1=receives video.
	* Invite_ReceiveRaw:int - Optional. 1=receives raw steno.
	* Invite_CanUpload:int - Optional. 1=File uploading is allowed.
	* Invite_CanDownload:int - Optional. 1=File downloading is allowed.
	* Invite_CanDownloadSubmitted:int - Optional. 1=Allow downloading submitted exhibits.
	* Invite_CanExhibit:int - Optional. 1=Can create and control an exhibit inside a deposition.
	* </code>
	*
	* Returns the ID of the newly created chat group, or 0 on failure.
	* DCG_Emails is deprecated. Do not use as support may be completely removed later.
	*
	* @param object Chat group object.
	* @return object New id and name.
	*/
	function createChatGroup($grpObj) {
		$db=$this->dbh;
		$out=array();
		$nuid=0;
		$emls="";
		$defaultPerms=array(
			"DCG_HasVOIP" => 0,
			"DCG_HasVideo" => 0,
			"DCG_HasGroupChat" => 1,
			"DCG_HasPrivateChat" => 1,
			"DCG_ExportSteno" => 0,
			"DCG_ReceiveSteno" => 1,
			"DCG_ReceiveAudio" => 1,
			"DCG_ReceiveVideo" => 1,
			"DCG_ReceiveRaw" => 0,
			"DCG_CanUpload" => 0,
			"DCG_CanDownload" => 0,
			"DCG_CanDownloadSubmitted" => 0,
			"DCG_CanExhibit" => 0
		);
		if (!isset($grpObj['Meet_ID']) || !isset($grpObj['DCG_Name'])) {
			$err="createChatGroup() - Missing Meet_ID/Group Name";
			$this->log2file($this->s_ServiceClass,$err);
			return 0;
		}
		$mtgID=$grpObj['Meet_ID'];

		// check for existing chat group
		$sg=mysql_escape_string($grpObj['DCG_Name']);
		$sql="select count(*) from DepoChatGroups where Meet_ID='$mtgID' && ".
		"DCG_Name='$sg' && DCG_EnableJoin=1";
		$res=mysql_query($sql,$db);
		if ($res) {
			$row=mysql_fetch_array($res);
			mysql_free_result($res);
		}
		if ($row[0]>0) {
			$err="createChatGroup() - Chat Group ".$grpObj['DCG_Name']." already exists.";
			$this->log2file($this->s_ServiceClass,$err);
			return 0;
		}
		// update defaults - these will be applied to individuals if not set.
		foreach ($defaultPerms as $k=>$v) {
			if (isset($grpObj[$k])) { $defaultPerms[$k] = $grpObj[$k]; }
		}
		// build DCG_Emails and individual permissions records
		$domain='';
		$sql="select Acc_Domain from Meetings a, Accounts b where a.Acc_ID=b.Acc_ID && Meet_ID='$mtgID'";
		$res=mysql_query($sql,$db);
		if ($res) {
			list($domain) = mysql_fetch_array($res);
			mysql_free_result($res);
		}
		$perms=array("HasVOIP", "HasVideo", "HasGroupChat","HasPrivateChat","ReceiveSteno",
		"ExportSteno", "ReceiveAudio","ReceiveVideo","ReceiveRaw","CanUpload","CanDownload",
		"CanDownloadSubmitted", "CanExhibit");
		$newTokens=array();

		if (isset($grpObj['DCG_Users'])) {
			foreach ($grpObj['DCG_Users'] as $userObj) {
				$email=$userObj['Invite_Email'];
				$uname=$userObj['Invite_Name'];
				$emlx="$uname<$email>";
				$emls.=mysql_escape_string($emlx).",";  // building DCG_Emails
				$guser=$email;
				$grcpt=$email;
				$tag=strpos($email,'<');
				if ($tag) {
					$guser = trim(substr($email,0,$tag),'\'" ');
					$grcpt = trim(substr($email,$tag),'<> ');
				}
				$asid=$this->createGUID();
				$dsi=$this->sglAuthToken($mtgID);
				// $lduser=urlencode($guser);
				// $proto=($ssl)?"https":"http"; // assume ssl - this is for livedepo
				// $depolink="https://$domain/guest/?name=$lduser&dskey=$dsi";
				$depolink="https://$domain/guest/?dskey=$dsi";
				$link=str_replace('+','%20',$depolink);
				$nuDat=array(
					"Invite_ID"=>$asid,
					"Meet_ID"=>$mtgID, //   "DCG_ID"=>$chatID,  - d'oh! this doesn't exist yet
					"Invite_ClientKey"=>$dsi,
					"Invite_Domain"=>$domain,
					"Invite_URL"=>$link,
					"Invite_Email" => $userObj['Invite_Email'],
					"Invite_Name" => $userObj['Invite_Name']
				);
				// setting individual perms - use default if not provided
				foreach($perms as $prmx) {
					$ix="Invite_$prmx";
					$gx="DCG_$prmx";
					$nuDat[$ix] = (isset($userObj[$ix]))? $userObj[$ix] : $defaultPerms[$gx];
				}
				$newTokens[]=$nuDat;
			}
			$emls = trim($emls,", ");
		}

		$alwd = $this->getCols("DepoChatGroups", array("Meet_ID", "DCG_ID", "DCG_Emails") );
		$cols="Meet_ID,DCG_Emails";
		$vals="'".$grpObj['Meet_ID']."','$emls'";
		foreach($alwd as $key) {
			if (!isset($grpObj[$key])) { continue; }
			$sv=mysql_escape_string($grpObj[$key]);
			$cols.=",$key";
			$vals.=",'$sv'";
		}
		$sql="insert into DepoChatGroups ($cols) values ($vals)";
		if (mysql_query($sql,$db)) {
			$nuid=mysql_insert_id($db);
			$gname=$grpObj['DCG_Name'];
			$out=array("DCG_ID"=>$nuid, "DCG_Name"=>$gname);
			// assignTokens() does 'extra' queries to figure out what to update.
			// Forget that, we KNOW these are new records.
			// $this->assignTokens($nuid);
			if (count($newTokens)) { $this->bulkAddTokenRecord($nuid,$newTokens); }
			else { $this->assignTokens($nuid); }
		} else {
			$err="createChatGroup() failed. SQL: $sql\n".mysql_error($db);
			$this->log2file($this->s_ServiceClass,$err);
		}
		return $out;
	}

	/**
	* Get deposition chat group list.
	* Returns a complex object having two properties: <i>participants</i>
	* and <i>streamManagers</i>.  Structure: <code>
	* participants:Array - Objects describing participants and permissions.
	* streamManagers:Array - Objects describing stream managers.
	* </code>
	*
	* The <i>participants</i>property is a list of objects that describe a
	* chat group associated to a meeting.
	* Each object has the properties:<code>
	* DCG_ID:int - Chat group ID.
	* Meet_ID:int - Deposition.
	* DCG_Name:String - Chat group name.
	* DCG_Password:String - Chat group password.
	* DCG_Emails:String - Comma-separated email list.
	* DCG_Users:Array - list of individual user objects.
	* DCG_HasVOIP:int - 1=VOIP is allowed for this group.
	* DCG_HasVideo:int - 1=Video is allowed for this group.
	* </code>
	*
	* Each element in the DCG_Users list should follow the structure:<code>
	* Invite_Email:String - Email address.
	* Invite_Name:String - User name.
	* Invite_HasVOIP:int - Optional. 1=VOIP allowed for this user.
	* Invite_HasVideo:int - Optional. 1=Video allowed for this user.
	* Invite_HasGroupChat:int - Optional. 1=group chat enabled.
	* Invite_HasPrivateChat:int - Optional. 1=private chat enabled.
	* Invite_ReceiveSteno:int - Optional. 1=receives steno updates.
	* Invite_ReceiveAudio:int - Optional. 1=receives audio.
	* Invite_ReceiveVideo:int - Optional. 1=receives video.
	* Invite_ReceiveRaw:int - Optional. 1=receives raw steno.
	* Invite_CanUpload:int - Optional. 1=File uploading is allowed.
	* Invite_CanDownload:int - Optional. 1=File downloading is allowed.
	* Invite_CanDownloadSubmitted:int - Optional. 1=Exhibit downloading is allowed.
	* Invite_CanExhibit:int - Optional. 1=Can create and control an exhibit inside a deposition.
	* </code>
	*
	* DCG_Emails is deprecated and may be completely omitted in the future.
	*
	* The <i>streamManagers</i> property is a list of stream managers and
	* their steno/video assignments. Each object in this list has the structure:<code>
	* email:String - Stenographer's email.
	* steno:int - 1 or 0 depending on the steno assignment.
	* video:int - 1 or 0 depending on the video assignment.
	* </code>
	*
	* The <i>steno</i> and <i>video</i> properties cannot both be zero at the same
	* time. If they are, it is an error and the record is invalid.
	*
	* @param int Meeting ID
	* @return Object Two lists of objects. One for stream managers, one for guests.
	*/
	function getChatGroups($meetID) {
		$db=$this->dbx;
		$out=array();
		$smg=array();
		$ptz=array();
		$tmp=array();
		// get participants
		$sql="select * from DepoChatGroups where Meet_ID=$meetID && DCG_EnableJoin=1";
		$res=mysql_query($sql,$db);
		if ($res) {
			while(($row=mysql_fetch_assoc($res))!==false) { $tmp[]=$row; }
			mysql_free_result($res);
		}
		foreach ($tmp as $dcg) {
			$gid=$dcg['DCG_ID'];
			$users=array();
			$sql="select Invite_Email, Invite_Name, Invite_HasVOIP, Invite_HasVideo, ".
			"Invite_HasGroupChat, Invite_HasPrivateChat, Invite_ReceiveSteno, Invite_ReceiveAudio, ".
			"Invite_ReceiveVideo, Invite_ReceiveRaw, Invite_CanUpload, Invite_CanDownload, ".
			"Invite_CanExhibit, Invite_ExportSteno, Invite_DownloadType, Invite_ClientKey, ".
			"Invite_CanDownloadSubmitted from InvitationList where DCG_ID='$gid' && Meet_ID='$meetID'";
			$res=mysql_query($sql,$db);
			if ($res) {
				while(($row=mysql_fetch_assoc($res))!=false) { $users[]=$row; }
				mysql_free_result($res);
			}
			$dcg['DCG_Users']=$users;
			$ptz[]=$dcg;
		}
		// now the stream managers
		$tmp=array();
		$sql="select Invite_Email, Invite_SMSteno, Invite_SMVideo from ".
		"InvitationList a, APISessions b where Invite_ID=AS_ID && DCG_ID=0 ".
		"&& a.Meet_ID='$meetID' order by AS_CreateDateTime";
		$res=mysql_query($sql,$db);
		if ($res) {
			while(($row=mysql_fetch_assoc($res))!=false) {
				$k = $row['Invite_Email'];
				$v = $row['Invite_SMSteno'].":".$row['Invite_SMVideo'];
				$tmp[$k] = $v;
			}
			mysql_free_result($res);
		}
		foreach($tmp as $k=>$v) {
			list ($sdp, $swf) = explode(":", $v);
			$smg[] = array(
				"email" => $k,
				"steno" => $sdp,
				"video" => $swf
			);
		}

		$out['participants']=$ptz;
		$out['streamManagers']=$smg;
		return $out;
	}

	/**
	* Update a chat group.
	* Update a deposition chat group. Requires an object that has the properties:<code>
	* DCG_ID:int - Required.
	* Meet_ID:int - Required.
	* DCG_Name:String
	* DCG_Password:String
	* DCG_Emails:String
	* DCG_Users:Array - List of individual user objects. See below for details.
	* DCG_HasVOIP:int
	* DCG_HasVideo:int
	* </code>
	*
	* DCG_ID, Meet_ID and one other property are required. The values are never changed.
	* Existing emails that are omitted from the DCG_Emails string will be removed from the
	* InvitationList table.
	*
	* As of 18 Oct 2012 - DCG_Emails is deprecated. Use of DCG_Users is preferred.
	* Each element of the DCG_Users array should follow the structure:<code>
	* Invite_Email:String - Email address.
	* Invite_Name:String - User name.
	* Invite_HasVOIP:int - Optional. 1=VOIP allowed for this user.
	* Invite_HasVideo:int - Optional. 1=Video allowed for this user.
	* Invite_HasGroupChat:int - Optional. 1=group chat enabled.
	* Invite_HasPrivateChat:int - Optional. 1=private chat enabled.
	* Invite_ExportSteno:int - Optional. 1=Allowed to markup steno.
	* Invite_ReceiveSteno:int - Optional. 1=receives steno updates.
	* Invite_ReceiveAudio:int - Optional. 1=receives audio.
	* Invite_ReceiveVideo:int - Optional. 1=receives video.
	* Invite_ReceiveRaw:int - Optional. 1=receives raw steno.
	* Invite_CanUpload:int - Optional. 1=File uploading is allowed.
	* Invite_CanDownload:int - Optional. 1=File downloading is allowed.
	* Invite_CanDownloadSubmitted:int - Optional. 1=Exhibit downloading is allowed.
	* Invite_CanExhibit:int - Optional. 1=Can create and control an exhibit inside a deposition.
	* </code>
	*
	* @param Object Update object
	* @return Boolean
	*/
	function updateChatGroup($updObj) {
		$db=$this->dbh;
		$out=false;
		if (!isset($updObj['DCG_ID']) || !isset($updObj['Meet_ID']) || count($updObj)<3) { return $out; }
		$gid=$updObj['DCG_ID'];
		$mtgID=$updObj['Meet_ID'];
		$ok2go=1;

		// if deactivating, make sure that there is at least one other active chat group.
		if (isset($updObj['DCG_EnableJoin']) && $updObj['DCG_EnableJoin']<1) {
			$qqq=" select count(b.Meet_ID) from DepoChatGroups a left join DepoChatGroups b ".
			"on a.Meet_ID=b.Meet_ID where a.DCG_ID=$gid";
			$res=mysql_query($qqq,$db);
			if ($res) {
				$row=mysql_fetch_array($res);
				mysql_free_result($res);
			}
			if ($row[0]<2) {
				$err="updateChatGroup() error. Can't remove last active chat group.";
				$this->log2file($this->s_ServiceClass,$err);
				if (defined("THROW_ERRORS")) { throw new Exception($err); }
				return $out;
			}
			// also delete tokens associated with this chat group
			$rmq="delete from InvitationList where DCG_ID=$gid";

			// more debugging...
			/*
			$trace="updateChatGroup() debug... removing tokens. $rmq";
			$this->log2file($this->s_ServiceClass,$trace);
			*/

			if (!mysql_query($rmq,$db)) {
				$trace="updateChatGroup() sql error. $rmq\n".mysql_error();
				$this->log2file($this->s_ServiceClass,$trace);
				if (defined("THROW_ERRORS")) { throw new Exception($trace); }
				return $out;
			} else { $ok2go=0; }    // don't issue new tokens.
		}
		$alwd = $this->getCols("DepoChatGroups", array("DCG_ID") );
		$upd="";
		foreach ($updObj as $key=>$val) {
			if (in_array($key,$alwd)) {
				if ($upd) { $upd.=","; }
				$sv=mysql_escape_string($val);
				$upd.="$key='$sv'";
			}
		}
		// if no valid keys, bail.
		if (!$upd) {
			$err="updateChatGroup() error. No valid fields.";
			$this->log2file($this->s_ServiceClass,$err);
			return $out;
		}

		$sql="update DepoChatGroups set $upd where DCG_ID=$gid";
		$out=mysql_query($sql,$db);
		if (!$out) {
			$err="updateChatGroup() failed. SQL: $sql\n".mysql_error($db);
			$this->log2file($this->s_ServiceClass, $err);
		}
		if ($ok2go>0) {
			if (isset($updObj['DCG_Users'])) {
				$this->manageTokenUpdates($mtgID, $gid,$updObj['DCG_Users']);
			} else { $this->assignTokens($gid); }
		}
		return $out;
	}

	/**
	* Get a list of tokens.
	* Returns an array of objects that should follow the structure:<code>
	* Meet_Name:String - Meeting name,
	* Meet_ScheduledDateTime:String - Scheduled time (UTC),
	* Meet_EndDateTime:String - Scheduled end date (UTC, optional),
	* Meet_DepoWitness:String - Witness name,
	* token:String - Valid user token.
	* name:String - User name associated with the token
	* email:String - Email associated with the token
	* </code>
	*
	* Requires valid account ID. If not accessing as admin, then account login ID
	* is also required. 3rd parameter limits results to the specified meeting ID
	* provided that it is an active meeting.
	*
	* @param int Account ID.
	* @param int AccountLogin ID.
	* @param int Meeting ID.
	*/
	function getTokenList($acct, $alid=0, $mtgid=0) {
		$db=$this->dbh;
		$out=array();
		if ($alid<1 && $_SESSION['role']!="admin") { return $out; }
		$sql="select Meet_Name, Meet_ScheduledDateTime, Meet_EndDateTime, Meet_DepoWitness, DCG_Name, ".
		"Invite_ClientKey as token, Invite_Name as name, Invite_Email as email from ".
		"Meetings a, InvitationList b, DepoChatGroups c where ".
		"a.Meet_ID=b.Meet_ID && a.Meet_ID=c.Meet_ID && b.DCG_ID=c.DCG_ID && Meet_EnableJoin=1 ".
		"&& Acc_ID=$acct && Invite_Email!='' && b.DCG_ID!=0";
		/*
		"select Meet_Name, Meet_ScheduledDateTime, Meet_DepoWitness, Invite_ClientKey ".
		"as token, Invite_Name as name, Invite_Email as email from Meetings a, InvitationList b where ".
		"a.Meet_ID=b.Meet_ID && Meet_EnableJoin=1 && Acc_ID=$acct && Invite_Email!='' ".
		"&& DCG_ID!=0";
		*/
		if ($alid) { $sql.="&& AL_ID=$alid "; }
		if ($mtgid) { $sql.="&& a.Meet_ID=$mtgid "; }
		/* uncomment to debug
		$trace="getTokenList() debug. SQL: $sql";
		$this->log2file($this->s_ServiceClass,$trace);
		*/
		$res=mysql_query($sql,$db);
		if ($res) {
			while(($row=mysql_fetch_assoc($res))!=false) { $out[]=$row; }
			mysql_free_result($res);
		}

		// bug 2755 - Now adding stream manager results.
		// bug 2795 - Use the EASY keys
		$sql="select Meet_Name, Meet_ScheduledDateTime, Meet_EndDateTime, ".
		"Meet_DepoWitness, 'Stream Manager' as DCG_Name, Invite_ClientKey as token, ".
		"Invite_Name as name, Invite_Email as email ".
		"from Meetings a, InvitationList b where ".
		"a.Meet_ID=b.Meet_ID && DCG_ID=0 && Meet_EnableJoin=1 && ".
		"Acc_ID=$acct && Invite_Email!=''";
		if ($alid) { $sql.="&& AL_ID=$alid "; }
		if ($mtgid) { $sql.="&& a.Meet_ID=$mtgid "; }
		$res=mysql_query($sql,$db);
		if ($res) {
			while(($row=mysql_fetch_assoc($res))!=false) { $out[]=$row; }
			mysql_free_result($res);
		}

		return $out;
	}

	/**
	* Set tokens for a specific group.
	* This is done automatically after a group is created or updated, but can be
	* called specifically to ensure that tokens are created.
	*
	* @param int Chat group ID.
	* @return Boolean
	*/
	function assignTokens($chatID) {
		$db=$this->dbh;
		$out=true;
		// Figure out Account,Meeting, and default data for a given group.
		$sql="select Acc_Domain, Acc_UseSSL, a.Meet_ID, DCG_Emails, DCG_HasVOIP, DCG_HasVideo, ".
		"DCG_HasGroupChat, DCG_HasPrivateChat, DCG_ExportSteno, DCG_ReceiveSteno, DCG_ReceiveAudio, ".
		"DCG_ReceiveVideo, DCG_ReceiveRaw, DCG_CanUpload, DCG_CanDownload, DCG_CanExhibit ".
		"from Meetings a, Accounts b, DepoChatGroups c where a.Acc_ID=b.Acc_ID && ".
		"a.Meet_ID=c.Meet_ID && DCG_ID=$chatID";
//      $this->log2file($this->s_ServiceClass,"assignTokens() debug. SQL: $sql");
		$res=mysql_query($sql,$db);
		if ($res) {
			list($domain,$ssl,$mtgID,$emladds, $hasVoip, $hasVideo, $hasGrpChat, $hasPvtChat, $xptSteno,
			$rcvSteno, $rcvAud, $rcvVideo, $rcvRaw, $canUpl, $canDwn, $canXibit ) = mysql_fetch_array($res);
			mysql_free_result($res);
		}
		$emlTmp=$this->emailParse($emladds); // the emails that should have tokens.
		// store address only, no other stuff.
		$addys=array();
		foreach ($emlTmp as $addr) { $addys[]=$this->emailNoTags($addr); }

		// build list of emails
		$hasToken=array();  // emails that do have tokens
		$toRevoke=array();  // emails that should NOT have tokens
		$sql="select Invite_ID, Invite_Email from InvitationList where Meet_ID=$mtgID && DCG_ID='$chatID'";
//      $this->log2file($this->s_ServiceClass,"assignTokens() debug. SQL: $sql");
		$res=mysql_query($sql,$db);
		if ($res) {
			while(($row=mysql_fetch_assoc($res))!=false) {
				// make sure it's email only, no name
				$xists = $this->emailNoTags($row['Invite_Email']);
				if (in_array($xists,$addys)) { $hasToken[] = $xists; }
				else { $toRevoke[] = $row['Invite_ID']; }
			}
			mysql_free_result($res);
		}

		// revocation
		$rvklist='"'.implode('","',$toRevoke).'"';
		$rvk="delete from InvitationList where Invite_ID in ($rvklist)";
		if (! mysql_query($rvk,$db)) {
			$trace="assignTokens() error. SQL: $sql; ".mysql_error();
			$this->log2file($this->s_ServiceClass,$trace);
		}

/*
		$trace="Has tokens:".print_r($hasToken,true);
		$trace.="In Group: ".print_r($addys,true);
		$this->log2file($this->s_ServiceClass,"assignTokens() debug. $trace");
*/
		// adding new tokens
		foreach($emlTmp as $email) {
			$cleanEml=$this->emailNoTags($email);
			if (in_array($cleanEml,$hasToken)) { continue; } // don't create a token if one exists
			$guser=$email;
			$grcpt=$email;
			$tag=strpos($email,'<');
			if ($tag) {
				$guser = trim(substr($email,0,$tag),'\'" ');
				$grcpt = trim(substr($email,$tag),'<> ');
			}
			// new validation code
			// $asid=$this->sglAuthToken($asid);
			$asid=$this->createGUID();
			$dsi=$this->sglAuthToken($mtgID);
			// $lduser=urlencode($guser);
			$proto=($ssl)?"https":"http";
			// $depolink="$proto://$domain/guest/?name=$lduser&dskey=$dsi";
			$depolink="$proto://$domain/guest/?dskey=$dsi";
			$link=str_replace('+','%20',$depolink);
			$nuDat=array(
				"Invite_ID"=>$asid,
				"Meet_ID"=>$mtgID,
				"DCG_ID"=>$chatID,
				"Invite_ClientKey"=>$dsi,
				"Invite_Domain"=>$domain,
				"Invite_URL"=>$link,
				"Invite_Email"=>$grcpt,
				"Invite_Name"=>$guser,
				"Invite_HasVOIP"=> $hasVoip,
				"Invite_HasVideo"=> $hasVideo,
				"Invite_HasGroupChat"=> $hasGrpChat,
				"Invite_HasPrivateChat"=> $hasPvtChat,
				"Invite_ExportSteno"=> $xptSteno,
				"Invite_ReceiveSteno"=> $rcvSteno,
				"Invite_ReceiveAudio"=> $rcvAudio,
				"Invite_ReceiveVideo"=> $rcvVideo,
				"Invite_ReceiveRaw"=> $rcvRaw,
				"Invite_CanUpload"=> $canUpl,
				"Invite_CanDownload"=> $canDwn,
				"Invite_CanExhibit"=> $canXibit
			);
			if (!$this->addTokenRecord($nuDat)) {
				$out=false;
			}
		}
		return $out;
	}

	private function addTokenRecord($info) {
		$db=$this->dbh;
		$cols="";
		$vals="";
		foreach ($info as $k=>$v) {
			if ($cols) { $cols.=","; $vals.=","; }
			$cols.=$k;
			$sv=mysql_escape_string($v);
			$vals.="'$sv'";
		}
		$sql="insert into InvitationList ($cols) values ($vals)";
		$out=mysql_query($sql,$db);
		if (!$out) {
			$trace="addTokenRecord() error. SQL: $sql\n".mysql_error();
			$this->log2file($this->s_ServiceClass,$trace);
		}
		return $out;
	}

	private function bulkAddTokenRecord($gid,$records) {
		$db=$this->dbh;
		/*
		$alwd=array( "Invite_ID", "Meet_ID", "DCG_ID", "Invite_ClientKey", "Invite_Domain",
			"Invite_URL", "Invite_Email", "Invite_Name", "Invite_HasVOIP", "Invite_HasVideo",
			"Invite_HasGroupChat", "Invite_HasPrivateChat", "Invite_ExportSteno", "Invite_ReceiveSteno",
			"Invite_ReceiveAudio", "Invite_ReceiveVideo", "Invite_ReceiveRaw", "Invite_CanUpload",
			"Invite_CanDownload", "Invite_CanExhibit");
		*/
		$alwd = $this->getCols("InvitationList", array("Tag_ID"));
		$cols=implode(",",$alwd);
		$sql="insert into InvitationList($cols) values";
		foreach($records as $recObj) {
			$tmp="";
			foreach($alwd as $col) {
				if ($col=="DCG_ID") { $sv=$gid; }
				else { $sv=(isset($recObj[$col]))? mysql_escape_string($recObj[$col]) : ""; }
				$tmp.="'$sv',";
			}
			$tmp=trim($tmp,",");
			$sql.="\n($tmp),";
		}
		$sql=trim($sql,",");
		// debug
		$trace="bulkAddTokenRecord() debug. SQL: $sql";
		$this->log2file($this->s_ServiceClass, $trace);

		if (!mysql_query($sql,$db)) {
			$trace="bulkAddTokenRecord() failed. SQL: $sql\n".mysql_error();
			$this->log2file($this->s_ServiceClass, $trace);
		}
	}

	private function manageTokenUpdates($mtg, $gid, $users) {
		$db=$this->dbh;

		// get list of current records
		$sql="select * from InvitationList where DCG_ID='$gid' && Meet_ID='$mtg'";
		$current=array();
		$aa=array();
		$bb=array();
		$res=mysql_query($sql,$db);
		if ($res) {
			while(($row=mysql_fetch_assoc($res))!=false) { $current[]=$row; }
			mysql_free_result($res);
		}
		// build lists of new, and current users
		foreach ($users as $usrObj) { $aa[]=$usrObj['Invite_Email']; }
		foreach ($current as $usrObj) { $bb[]=$usrObj['Invite_Email']; }

		$todo=array_diff($bb,$aa);
		// entries in bb, but not aa get revoked
		if (count($todo)) {
			$rmq="delete from InvitationList where DCG_ID='$gid' && Meet_ID='$mtg' && Invite_Email in (";
			foreach($todo as $eml) {
				$tmp=mysql_escape_string($eml);
				$rmq.="'$eml',";
			}
			$rmq=trim($rmq,",");
			$rmq.=")";
			// debug
			$trace="manageTokenUpdates() revocation debug. $rmq";
			$this->log2file($this->s_ServiceClass,$trace);

			$ok=mysql_query($rmq,$db); // delete revoked
			if (!$ok) {
				$trace="manageTokenUpdates() revocation error. SQL: $rmq\n".mysql_error();
				$this->log2file($this->s_ServiceClass,$trace);
				return;
			}
		}

		// add new
		$todo=array_diff($aa,$bb);
		// entries in aa, but not bb get added
		if (count($todo)) {
			// get data to build links
			$sql="select Acc_Domain, Acc_UseSSL from Accounts a, Meetings b ".
			"where a.Acc_ID=b.Acc_ID && Meet_ID='$mtg'";
			$res=mysql_query($sql,$db);
			if ($res) {
				list($domain, $ssl)=mysql_fetch_array($res);
				mysql_free_result($res);
			}
			// now build new records
			$newTokens=array();
			foreach ($todo as $eml) {
				$nuDat=array();
				// get the record from 'users' argument
				foreach($users as $usrObj) {
					if ($usrObj['Invite_Email'] == $eml) { $nuDat=$usrObj; }
				}
				if (isset($nuDat['Invite_Email'])) {
					$email=$nuDat['Invite_Email'];
					$guser=$email;
					$grcpt=$email;
					$tag=strpos($email,'<');
					if ($tag) {
						$guser = trim(substr($email,0,$tag),'\'" ');
						$grcpt = trim(substr($email,$tag),'<> ');
					}
					$dsi=$this->sglAuthToken($mtgID);
					// $lduser=urlencode($guser);
					$proto=($ssl)?"https":"http";
					$depolink="$proto://$domain/guest/?dskey=$dsi";
					$link=str_replace('+','%20',$depolink);
					$nuDat['Invite_ID'] = $this->createGUID();
					$nuDat["Meet_ID"]= $mtg;
					$nuDat["DCG_ID"]= $gid;
					$nuDat["Invite_ClientKey"]=$dsi;
					$nuDat["Invite_Domain"]=$domain;
					$nuDat["Invite_URL"]=$link;
					$newTokens[]=$nuDat;
				}
			}
			$this->bulkAddTokenRecord($gid,$newTokens);
		}

		// do updates
		$todo=array_intersect($aa,$bb); // entries in both get updated
		$props = $this->getCols("InvitationList",
			array("Invite_ID", "Meet_ID", "DCG_ID", "Invite_ClientKey",
			"Invite_Domain", "Invite_URL", "Invite_Email")
		);
		foreach($todo as $eml) {
			// find current db value
			$nuDat=array();
			$olDat=array();
			foreach($current as $usrObj) {
				if ($usrObj['Invite_Email']==$eml) { $olDat=$usrObj; }
			}
			foreach($users as $usrObj) {
				if ($usrObj['Invite_Email'] == $eml) { $nuDat=$usrObj; }
			}
			$upd="";
			$inv=$olDat['Invite_ID'];
			// find differences in permissions.
			foreach($props as $col) {
				if ($nuDat[$col] != $olDat[$col]) {
					$xval=mysql_escape_string($nuDat[$col]);
					$upd.="$col = '$xval',";
				}
			}
			$upd=trim($upd,",");
			if ($upd) {
				$sql="update InvitationList set $upd where Invite_ID='$inv'";
				//debug
				$trace="manageTokenUpdates() debug. $sql\n";
				$this->log2file($this->s_ServiceClass,$trace);
				if (!mysql_query($sql,$db)) {
					$trace="manageTokenUpdates() update error. SQL: $sql\n".mysql_error();
					$this->log2file($this->s_ServiceClass,$trace);
				}
			} // end if
		}   // end foreach ($todo as $eml)
	}

	/**
	* Re-issue a token.
	* Requires a valid email and the currently associated token.
	* Returns the updated token.
	*
	* @param String Valid email.
	* @param String Current token.
	* @return String New token.
	*/
	function reIssueToken($email, $curToken) {
		$db=$this->dbh;
		$out="";
		$inv=""; $url=""; $mtgID=0;
		$tkn=mysql_escape_string($curToken);
		$eml=mysql_escape_string($email);
		$sql="select Invite_ID,Invite_Url, Meet_ID from InvitationList where ".
		"Invite_Email='$eml' && Invite_ClientKey='$tkn'";
		$res=mysql_query($sql,$db);
		if ($res) {
			list ($inv,$url,$mtgID)=mysql_fetch_array($res);
			mysql_free_result($res);
		}
		if (!$inv) {
			$trace="reIssueToken() Can't proceed. Email: $email, Token: $curToken, SQL: $sql";
			$this->log2file($this->s_ServiceClass,$trace);
			return $out;
		}

		$dsi=$this->sglAuthToken($mtgID);
		$nu=str_replace($curToken,$dsi,$url);
		$sql="update InvitationList set Invite_ClientKey='$dsi', Invite_URL='$nu' ".
		"where Invite_ID='$inv'";
		if (mysql_query($sql,$db)) {
			$out=$dsi;
		} else {
			$trace="reIssueToken() did not update. SQL:$sql\n".mysql_error();
			$this->log2file($this->s_ServiceClass,$trace);
			$out='failed';
		}
		return $out;
	}

	private function getTokenData($mtgID, $email, $privileged=0) {
		$db=$this->dbh;
		$out=array();
		$sql="select Invite_ClientKey, Invite_URL from InvitationList ".
		"where Meet_ID='$mtgID' && Invite_Email='$email'";
		if (!$privileged) { $sql.=" && DCG_ID!=0"; }
		$res=mysql_query($sql,$db);
		if ($res) {
			$out=mysql_fetch_assoc($res);
			mysql_free_result($res);
		}
		if (isset($out['Invite_URL'])) { return $out; }

	}

	private function sglAuthToken($mtgid) {
		// $dsi=chr(rand(103,122)).dechex($mtgID).$this->sglAuthToken();
		/* Bug 3040.
		The keys tend to have alternating numeric and alpha characters. On
		mobile devices, it is possible to have numbers and characters on different
		"keyboards". This means that the user will be forced to switch between
		keyboards *often* which results in additional keystrokes and eventually
		a greater chance of inaccurate entries. Simplify by grouping letters
		and numbers together.

		It used to be that the meeting id was encoded in the key - to provide
		additional validation measures. However, nothing more than a simple
		plaintext comparison has ever been necessary, so that step will no
		longer occur.
		*/
		$chr="abcdefghjkmnpqrstvwxyz";
		$out = "";
		// Note that the character set  is limited to reduce the
		// likelihood that unprofessional words are generated.
		$mx = strlen($chr)-1;
		$num="1234567890";
		$ltr="";
		while (strlen($out)<12) {
			if (strlen($out)<6) {
				$rx=rand(0,$mx);
				$ltr=substr($chr,$rx,1); }
			else {
				$rx=rand(0,9);
				$ltr=substr($num,$rx,1);
			}
			$out.=$ltr;
		}
		return $out;
	}

	private function emailParse($list) {
		$out=array();
		$bpos=0;    // beginning position
		$epos=0;    // ending position
		$mpos=0;    // middle position
		$len=strlen($list);
		while ($epos < $len) {
			// look for @ sign
			$mpos=strpos($list,'@',$bpos);
			// look for ,
			$epos=strpos($list,',',$mpos);
			if ($epos) {
				$sub=$epos - $bpos;
				$out[] = substr($list,$bpos,$sub);
				$bpos=$epos+1;
			} else {
				$epos=$len;
				$out[] = substr($list,$bpos);
			}
		}
		return $out;
	}
	private function emailNoTags($addr) {
		$tmp=strpos($addr,'<');
		if ($tmp!==false) {
			$base=substr($addr,$tmp);
			$out=trim($base,'<> ');
		} else {
			$out=$addr;
		}
		return $out;
	}

	/**
	* Manage downloading restrictions in depo.
	* Part of bugfix 3456.
	* This method is meant to be used by a host inside of a deposition. It affects
	* the permissions columns in the InvitationList table.
	*
	* The required argument is an object which should follow the structure:<code>
	* Meet_ID:int - Meeting ID.
	* users:Object - Associative list of objects that outline the updates.
	* </code>
	*
	* Each property in the "users" object is labeled as a user's respective depo
	* session key. It holds an object having one or more of these properties:<code>
	* Invite_CanUpload:int
	* Invite_CanExhibit:int
	* Invite_CanDownload:int
	* Invite_CanDownloadSubmitted:int
	* Invite_DownloadType:String
	* </code>
	*
	* Any of the properties not defined will simply not be updated.
	*
	* @param Object See above for description.
	* @return Boolean True on success.
	*/
	function setDownloadPerms($obj) {
		$db=$this->dbh;
		$out = false;
		$ok = 0;
		$ttl = 0;
		// parse the object
		if (!isset($obj['Meet_ID'])) {
			$trace="setDownloadPerms(). Meeting ID not specified.";
			$this->log2file($this->s_ServiceClass, $trace);
			return $out;
		}
		$props = array("Invite_CanUpload", "Invite_CanDownload", "Invite_CanDownloadSubmitted",
			"Invite_CanExhibit", "Invite_DownloadType");
		$mtgID=$obj['Meet_ID'];
		foreach($obj['users'] as $key=>$perms) {
			$dskey = mysql_escape_string($key);
			$sql = "update InvitationList set ";
			$setq = "";
			foreach($props as $col) {
				if (isset($perms[$col])) {
					$val = mysql_escape_string($perms[$col]);
					$setq.="$col='$val',";
				}
			}
			$setq = trim($setq,",");
			if (!$setq) { continue; }

			$sql.= "$setq where Invite_ClientKey='$dskey'";
			if (mysql_query($sql, $db)) { $ok++; }
			else {
				$trace="setDownloadPerms() query failed. SQL: $sql\n".mysql_error();
				$this->log2file($this->s_ServiceClass, $trace);
			}
			$ttl++;
		}
		if ($ok == $ttl) { $out = true; }
		return $out;
	}

/* - - - - - end deposition mod support functions - - - - - */

/* - - - - - begin skin support functions - - - - - */
	/**
	* Get a list of skins.
	* Returns a list of Skin ID's and names available to a specific account login.
	* Each element in the returned array will be an object that has the structure:
	* <code>
	* label:String - The human-readable skin name.
	* data:int - The Skin ID.
	* </code>
	*
	* If no skins are supported, the method returns an empty array.
	*
	* @param int Account login ID.
	* @return array List of objects. See description.
	*/
	function getSkinList($alid, $showDefault=0) {
		$db=$this->dbh;
		$out=array();
		$sql="select a.Skin_ID as data, Skin_Name as label from Skins a, AL2Skin b ".
		"where a.Skin_ID=b.Skin_ID && AL_ID='$alid'";
		if ($showDefault) {
			$sql="select a.Skin_ID, Skin_Name, ".
			"if(a.Skin_ID=AL_DefaultMeetSkin,'1','0') as isDefault ".
			"from Skins a, AL2Skin b, AccountLogins c where ".
			"a.Skin_ID=b.Skin_ID && b.AL_ID=c.AL_ID && b.AL_ID='$alid'";
		}
		$res=mysql_query($sql,$db);
		if ($res) {
			while (($row=mysql_fetch_assoc($res))!=false) { $out[]=$row; }
			mysql_free_result($res);
		}
		return $out;
	}

/* - - - - - end skin support functions - - - - - */


/* - - - - - begin meeting template support functions - - - - - */

/**
* Get a list of meeting templates.
* Returns an array of meeting template objects. Each object follows
* the structure:<code>
* id:int - Template ID
* label:String - Template name.
* data:String - Blob content.
* tooltip:String - Short description.
* </code>
*
* Requires a valid account id.
*
* @param int Account ID.
* @return Array List of template objects. See description.
*/
function getMeetingTemplateList($acct) {
	$db=$this->dbh;
	$out=array();
	$sql="select MeetingTmpl_ID as id, MeetingTmpl_Name as label, MeetingTmpl_Data as data, ".
	"MeetingTmpl_Description as tooltip from MeetingTemplates where Acc_ID='$acct'";
	$res=mysql_query($sql,$db);
	if ($res) {
		while(($row=mysql_fetch_assoc($res))!=false) { $out[]=$row; }
		mysql_free_result($res);
	}
	return $out;
}
/**
* List of meeting templates accessible by login.
* Returns a list of Meeting templates available to the specified login ID.
*
* @param int Account Login ID.
* @return Array. List of Meeting template objects.
*/
function getMeetingTemplatesByLogin($alid) {
	$db=$this->dbh;
	$out=array();
	$sql="select a.MeetingTmpl_ID as id, MeetingTmpl_Name as label, MeetingTmpl_Data as data, ".
	"MeetingTmpl_Description as tooltip from AL2MT a, MeetingTemplates b where ".
	"a.AL_ID='$alid' && a.MeetingTmpl_ID=b.MeetingTmpl_ID";
	$res=mysql_query($sql,$db);
	if ($res) {
		while(($row=mysql_fetch_assoc($res))!=false) { $out[]=$row; }
		mysql_free_result($res);
	}
	return $out;
}
/* - - - - - end meeting template support functions - - - - - */

/* - - - - - begin QuickChat support functions - - - - - */
	/**
	* Insert a new QuickChat message.
	* Creates a single QuickChats record in the database.
	*
	* @param int AccountLogin ID.
	* @param String Message body.
	* @return int New QuickChat message ID.
	*/
	function createQuickChat($alid, $data) {
		$db = $this->dbh;
		$out = 0;
		mysql_query("set names utf8", $db);

		$sv = mysql_escape_string($data);
		$sql = "insert into QuickChats (AL_ID, QC_Text) values ('$alid', '$sv')";
		if (mysql_query($sql, $db)) {
			$out=mysql_insert_id();
		} else {
			$trace = "createQuickChat() failed. SQL: $sql\n".mysql_error();
			$this->log2file($this->s_ServiceClass, $trace);
			if (defined("THROW_ERRORS")) { throw new Exception($trace); }
		}
		return $out;
	}

	/**
	* Retrieve QuickChat messages.
	* Returns an array of message objects. Each object should be
	* structured as follows:<code>
	* id:int - Numeric ID. Use this when editing/deleting.
	* message:String - Message body.
	* </code>
	*
	* @param int AccountLogin ID.
	* @return Array List of message objects.
	*/
	function getAllQuickChats($alid) {
		$db = $this->dbh;
		$out = array();
		$lg = mysql_escape_string($alid);
		$sql = "select QC_ID as id, QC_Text as message from QuickChats where AL_ID='$lg'";
		$res=mysql_query($sql,$db);
		if ($res) {
			while(($row=mysql_fetch_assoc($res))!=false) { $out[] = $row; }
			mysql_free_result($res);
		}

		return $out;
	}

	/**
	* Edit a QuickChat message.
	* Replaces existing message data with the provided data.
	*
	* @param int QuickChat ID
	* @param String Message data.
	* @return Boolean True on success.
	*/
	function updateQuickChat($qcid, $data) {
		$db = $this->dbh;
		$out = false;
		mysql_query("set names utf8", $db);
		$sv = mysql_escape_string($data);
		$qq = intval($qcid);

		$sql = "update QuickChats set QC_Text='$sv' where QC_ID='$qq'";
		$out = mysql_query($sql, $db);

		if (!$out) {
			$trace="updateQuickChat() failed. SQL: $sql\n".mysql_error();
			$this->log2file($this->s_ServiceClass, $trace);
			if (defined("THROW_ERRORS")) { throw new Exception($trace); }
		}

		return $out;
	}

	/**
	* Delete a QuickChat message.
	* Removes a QuickChat message from an AccountLogin's set
	* of messages.
	*
	* @param int QuickChat ID
	* @return Boolean True on success.
	*/
	function deleteQuickChat($qcid) {
		$db = $this->dbh;
		$out = false;
		$qq = mysql_escape_string($qcid);
		$sql = "delete from QuickChats where QC_ID='$qq'";
		$out = mysql_query($sql, $db);

		if (!$out) {
			$trace="updateQuickChat() failed. SQL: $sql\n".mysql_error();
			$this->log2file($this->s_ServiceClass, $trace);
			if (defined("THROW_ERRORS")) { throw new Exception($trace); }
		}

		return $out;
	}

	/**
	* Populate QuickChats table.
	* Selects the default messages from QuickChatDefaults and copies
	* them into the QuickChats table. See also bulkCreateQuickChats().
	*
	* @param int AccountLogin ID
	* @return Boolean True on success.
	*/
	function loadDefaultQuickChats($alid) {
		$db = $this->dbh;
		$out = false;
		$lgn = intval($alid);
		mysql_query("set names utf8", $db);
		$sql="insert into QuickChats (AL_ID, QC_Text) select '$lgn', QCD_Text ".
		"from QuickChatDefaults a, AccountLogins b where a.Acc_ID=b.Acc_ID && ".
		"AL_ID='$lgn'";
		/*
		$trace="loadDefaultQuickChats() debug. SQL: $sql\n";
		$this->log2file($this->s_ServiceClass, $trace);
		*/
		$out = mysql_query($sql, $db);
		$afx = mysql_affected_rows($db);
		if ($afx<1) {
			// default global QuickChats
			$sql="insert into QuickChats (AL_ID, QC_Text) select '$lgn', QCD_Text ".
			"from QuickChatDefaults where Acc_ID='131'";
			/*
			$trace="loadDefaultQuickChats() debug. SQL: $sql\n";
			$this->log2file($this->s_ServiceClass, $trace);
			*/

			$out = mysql_query($sql, $db);
		}

		if (!$out) {
			$trace="loadDefaultQuickChats() failed. SQL: $sql\n".mysql_error();
			$this->log2file($this->s_ServiceClass, $trace);
			if (defined("THROW_ERRORS")) { throw new Exception($trace); }
		}

		return $out;
	}

	/**
	* Bulk creation of QuickChat records.
	* Facilitates creation of more than 1 QuickChat message at a time.
	* Similar to loadDefaultQuickChats(), but creates records from user
	* input rather than the database.
	*
	* @param int AccountLogin ID.
	* @param Array List of new messages.
	* @return int Number of newly created records.
	*/
	function bulkCreateQuickChats($alid, $msgList) {
		$db = $this->dbh;
		$out = 0;
		if (!is_array($msgList)) { return $out; }
		mysql_query("set names utf8", $db);
		// build query
		$lg=intval($alid);
		$sql="insert into QuickChats (AL_ID, QC_Text) values ";
		$mct = 0;
		foreach ($msgList as $msg) {
			if ($mct) { $sql.=", "; }
			$sv=mysql_escape_string($msg);
			$sql.="('$lg', '$sv')";
			$mct++;
		}
		$out = mysql_query($sql, $db);

		if (!$out) {
			$trace="bulkCreateQuickChats() failed. SQL: $sql\n".mysql_error();
			$this->log2file($this->s_ServiceClass, $trace);
			if (defined("THROW_ERRORS")) { throw new Exception($trace); }
		}

		return $out;
	}

/* - - - - - end QuickChat support functions - - - - - */

/* - - - - - Begin recording disk space reporting functions - - - - - */

	/**
	* Track recording resources.
	* Checks to see that recording is permitted as well as having
	* resources available.
	*
	* @param int AccountLogin ID.
	* @param int Account ID. Required if not used in AMFPHP context.
	* @return Object
	*/
	function getAvailableRecSpace($alid, $acx=0) {
		$db=$this->dbh;
		$out=array(
			"AccMBLimit" => 0,
			"AccMBUsage" => 0
		);
		$lgnUsage=array();
		/* This method should also be usable in the API. So we allow for
		parameters that are normally set in amfphp session.

		$alid can be zero if this is an 'admin' call.
		*/
		$acct = (isset($_SESSION['acct']))? $_SESSION['acct'] : $acx;
		$rcx = ($alid<1 && $acx>0)? "admin" : "host";
		$role = (isset($_SESSION['role']))? $_SESSION['role'] : $rcx;
		if ($alid<1 && $acct<1) { return $out; }

		$ok2r=0;    // recording enabled
		$acmb=0; // acct MB limit
		$sfa = intval($acct);

		/* query 1 */
		$sql="select Acc_EnableRecording, Acc_RecMBLimit from Accounts where Acc_ID='$sfa'";
		$res=mysql_query($sql,$db);
		if ($res) {
			list ($ok2r, $acmb)=mysql_fetch_array($res);
			if ($ok2r) {
				$out['AccMBLimit'] = $acmb;
			}
			mysql_free_result($res);
		}
		if (!$ok2r) { return $out; }

		/* query 2
		select b.AL_ID, AL_UserName,
		sum(substr(Rec_PackageInfo,1,(locate(':',Rec_PackageInfo)-1)))/(1024*1024) as mbUsed,
		AL_RecMBLimit from Recordings a, Meetings b , AccountLogins c where a.Meet_ID=b.Meet_ID
		&& b.AL_ID=c.AL_ID && Rec_DeleteFlag=0 && b.Acc_ID=136 group by AL_ID;
		*/
		$ttl=0;
		$sql="select b.AL_ID, AL_UserName, ".
		"sum(substr(Rec_PackageInfo,1,(locate(':',Rec_PackageInfo)-1)))/(1024*1024) as mbUsed, ".
		"AL_RecMBLimit from Recordings a, Meetings b , AccountLogins c where a.Meet_ID=b.Meet_ID ".
		"&& b.AL_ID=c.AL_ID && Rec_DeleteFlag=0 && b.Acc_ID='$sfa' group by AL_ID";
		$res=mysql_query($sql,$db);
		if ($res) {
			while(($row=mysql_fetch_assoc($res))!=false) {
				$usage += $row['mbUsed'];
				if ($alid<1 || $alid == $row['AL_ID']) {
					$uuu = sprintf("%0.3f", $row['mbUsed']);
					$item=array(
						"ID" => $row['AL_ID'],
						"Username" => $row['AL_UserName'],
						"MBLimit" => $row['AL_RecMBLimit'],
						"MBUsage" => $uuu
					);
					$lgnUsage[] = $item;
				}
			}
			mysql_free_result($res);
		}

		$out['AccMBUsage'] = sprintf("%0.3f", $usage);
		$out['HostDetails'] = $lgnUsage;
		return $out;
	}

	// not related to recording disk space, but at least to recording...

	/**
	* Remuxer queue.
	* Shows the status of a recording in the remuxer queue. If not in the
	* queue, and the system can bear another entry, a new entry is made.
	*
	* Returns status object which has the structure:<code>
	* status:int - 1 on success, 0 if failed.
	* message:string - Detailed status or error message.
	* </code>
	*
	* @param int Recording ID
	* @param int Account ID.
	* @return Object
	*/
	function recordingMuxer($recid, $accid=0) {
		$db = $this->dbh;
		if (isset($_SESSION['acct'])) { $accid = $_SESSION['acct']; }
		$rcid = mysql_escape_string($recid);
		$acct = mysql_escape_string($accid);
		$out = array(
			"status" => 0,
			"message" => ""
		);
		$sql = "select RMX_Details, Acc_ID from RemuxQueue a, Recordings b, Meetings c where ".
		"a.Rec_ID=b.Rec_ID && b.Meet_ID=c.Meet_ID && a.Rec_ID='$rcid'";
		$res = mysql_query($sql,$db);
		$dtls = "";
		if ($res) {
			list ($dtls, $match) = mysql_fetch_array($res);
			mysql_free_result($res);
			if ($dtls) {
				if ($match != $accid) { $out['message'] = "Invalid ID."; }
			} else { $out['status'] = 1; }
		}
		if (!$dtls) {
			$myHost = $this->acct2rmxhost($acct);
			$qname = "-$myHost";
			$sql = "select count(*) from RemuxQueue where RMX_Host='$qname'";
			$sct = 0;
			$res = mysql_query($sql, $db);
			if ($res) {
				list ($sct) = mysql_fetch_array($res);
				mysql_free_result($res);
			}

			if ($sct<2) {
				$stx = "Queued. Awaiting worker thread.";
				$out['message'] = $stx;
				$sql = "insert into RemuxQueue (Rec_ID, RMX_Details) values ('$rcid', '$stx')";
				mysql_query($sql, $db);
				$out['status'] = 1;
			} else {
				$out['message'] = "System limit. Unable to queue recording at this time.";
			}
		}

		return $out;
	}

	private function acct2rmxhost($acct) {
		// eventually, need to have some kind of dynamic assignment
		$montage = array(295, 932, 1520);
		if (in_array($acct, $montage)) { $out = "montagenj01"; }
		else { $out = "devlax01"; }
		return $out;
	}
/* - - - - - End recording disk space reporting functions - - - - - */

/* - - - - - Begin timezone favorites support - - - - - */
	/**
	* Add a ZoneID to favorites.
	*
	* @param String A timezone ID (TZ_ZoneID).
	* @param int A valid login ID.
	* @return Boolean
	*/
	function addTZFavorite($zoneid, $lgid) {
		$db = $this->dbh;
		$alid=intval($lgid);    // ensure numeric value
		$out = false;
		$sql = "select AL_TZFavorites from AccountLogins where AL_ID='$alid'";
		$res=mysql_query($sql,$db);
		if ($res) {
			list ($fav) = mysql_fetch_array($res);
			mysql_free_result($res);
		}
		$favlist = explode(',', $fav);
		$bpos = array_search($zoneid, $favlist);
		if ($bpos===false) {
			$favlist[]=$zoneid;
			$nulist = implode(',', $favlist);
			$out = $this->setTZFavorites($nulist, $alid);
		}
		return $out;
	}

	/**
	* Remove a ZoneID from favorites.
	*
	* @param String A timezone ID (TZ_ZoneID).
	* @param int A valid login ID.
	* @return Boolean
	*/
	function deleteTZFavorite($zoneid, $lgid) {
		$db = $this->dbh;
		$out = false;
		$alid=intval($lgid);    // ensure numeric value
		$sql = "select AL_TZFavorites from AccountLogins where AL_ID='$alid'";
		$res=mysql_query($sql,$db);
		if ($res) {
			list ($fav) = mysql_fetch_array($res);
			mysql_free_result($res);
		}
		$favlist = explode(',', $fav);
		$bpos = array_search($zoneid, $favlist);
		if ($bpos!==false) {
			array_splice($favlist, $bpos, 1);
			$nulist = implode(',', $favlist);
			$out = $this->setTZFavorites($nulist, $alid);
		}
		return $out;
	}

	/**
	* Define the list of preferred timezones.
	*
	* @param String A comma-delimited list of timezone IDs.
	* @param int A valid login ID.
	* @return Boolean
	*/
	function setTZFavorites($zonelist, $alid) {
		$db = $this->dbh;
		$out = false;
		$zz = mysql_escape_string($zonelist);

		// debug
		// $trace = "setTZFavorites() debug: $zonelist";
		// $this->log2file($this->s_ServiceClass, $trace);

		if (strlen($zonelist)>248) {
			$trace = "setTZFavorites() failed. Zone list too long: $zonelist\n";
			$this->log2file($this->s_ServiceClass, $trace);
			return $out;
		}
		$sql = "update AccountLogins set AL_TZFavorites = '$zz' where AL_ID='$alid'";
		$out = mysql_query($sql, $db);
		if (!$out) {
			$trace = "setTZFavorites() error. SQL: $sql\n".mysql_error();
			$this->log2file($this->s_ServiceClass, $trace);
		}
		return $out;
	}

/* - - - - - End timezone favorites support - - - - - */

	/**
	* Get list of supported currencies.
	* Returns a list of Paypal supported currencies. Each item in the list
	* is a currency object that follows the structure:<code>
	* Cur_Code:String - Three letter currency code.
	* Cur_Name:String - Nationality and currency name.
	* </code>
	*
	* @return Array List of currency objects.
	*/
	function getCurrencies() {
		$db = $this->dbh;
		$out = array();
		$sql = "select Cur_Code, Cur_Name from Currencies";
		$res=mysql_query($sql,$db);
		if ($res) {
			while (($row=mysql_fetch_assoc($res))!=false) { $out[] = $row; }
			mysql_free_result($res);
		}
		return $out;
	}

	/*
	* Testing getCols.
	*
	* @param String Table name.
	* @param Array Optional list of column names to ignore.
	* @return Array List of column names.
	function testCols($table, $xcl=array()) {
		$res = $this->getCols($table, $xcl);
		return $res;
	}
	*/

	/**
	* Invitation footer.
	*/
	private function getFooter() {
		$db=$this->dbx;
		$out="";
		// Account ID is always available from authorized users.
		if (!isset($_SESSION['acct'])) { return $out; }
		$acct=$_SESSION['acct'];
		$sql="select Ivf_Body from InvFooters where Acc_ID in(0,$acct) order by Acc_ID";
		$res=mysql_query($sql,$db);
		if ($res) {
			// At least one record will be returned
			while (($row=mysql_fetch_array($res))!=false) { $out=$row[0]; }
			mysql_free_result($res);
		}
		return $out;
	}

	private function getMyDefaults($alid) {
		$db=$this->dbx;
		$out=array();
		$sql=<<<END_QUERY
select AL_UserName, AL_MeetingSeats, AL_DefaultVideoProfile, AL_MSDefaultProtocol, AL_MSDefaultPort,
AL_DefaultMeetSkin, b.Version_ID, b.Acc_MSDomain, b.Acc_MSEnableLoadBalance, LBMS_Group, b.Acc_Area,
b.Acc_MSApplication, b.Acc_VideoRateMin, b.Acc_VideoRateMax, b.Acc_VideoRateDefault,
b.Acc_VideoRateHQ, b.Acc_VideoResolutionMin, b.Acc_VideoResolutionMax,
b.Acc_VideoResolutionDefault, b.Acc_VideoResolutionHQ,
b.Acc_VideoCompressionMin, b.Acc_VideoCompressionMax,
b.Acc_VideoCompressionDefault, b.Acc_VideoCompressionHQ,
b.Acc_VideoHQSeats, b.Acc_EnableInvisibleHost, Acc_MaxVideos, Acc_MaxAudio,
b.Acc_NoiseCancelCtrl, Acc_EnableBusinessBundle, Acc_EnableMeetUsNow,
Acc_DepoTranscriptRate, Acc_DepoAVRate, Acc_Domain
from AccountLogins a, AccountSettingProfiles b , Accounts c
where a.Asp_ID=b.Asp_ID && a.Acc_ID=c.Acc_ID && AL_ID=$alid
END_QUERY;

		$res=mysql_query($sql,$db);
		if ($res) {
			$out=mysql_fetch_assoc($res);
			mysql_free_result($res);
		}

		if (!isset($out['Acc_MaxVideos'])) {
			$trace = "getMyDefaults() query: $sql\n\tRESULT: ".print_r($out, true);
			$this->log2file($this->s_ServiceClass, $trace);
		}
		return $out;
	}

	/**
	* Update stream manager keys.
	* When updating a deposition (meeting), there was previously no limit on the
	* number of keys associated to an email. When removed, then added again, another
	* key would be created.
	*
	* As of bug 3741, this method should issue new keys. Previously, this
	* method did not issue new keys, but it did deactivate keys if a stream
	* manager was not listed in the stream manager argument.
	*
	* @param int Meeting ID.
	* @param String Comma-separated list of allowed stream manager emails.
	* @return Boolean True on success.
	*/
	private function currentStreamManagers($n_MtgID, $s_StreamMgrs) {
		$db=$this->dbh;
		$out=true;
		$dom = "";
		$acct = 0;
		// Allowed emails
		$trm=trim($s_StreamMgrs, ", ");
		$rqtmp = explode(",", $s_StreamMgrs);
		$hlist = array();
		// trim "tags" from emails.
		foreach($rqtmp as $tmail) {
			$tag=strpos($tmail,"<");
			if ($tag===false) { $rmail = $tmail; }
			else { $rmail = trim( substr($tmail,$tag), "<> "); }
			$hlist[] = $rmail;  // populate hlist
		}

		// get existing
		$xst=array();
		$xsk=array();
		$sql="select Invite_ID, Invite_Email, Invite_ClientKey, b.Acc_ID, Acc_Domain from ".
		"InvitationList a, Meetings b, Accounts c where a.Meet_ID=b.Meet_ID && ".
		"b.Acc_ID=c.Acc_ID && a.Meet_ID='$n_MtgID' && DCG_ID=0 && Invite_Email!=''";
		$res=mysql_query($sql,$db);
		if ($res) {
			while(($row=mysql_fetch_assoc($res))!=false) {
				if (!$dom) {
					$dom = $row['Acc_Domain'];
					$acct = $row['Acc_ID'];
				}
				$tmpEml=$row['Invite_Email'];
				$tmpSID=$row['Invite_ID'];
				$xst[$tmpEml] = $tmpSID;
				$xsk[$tmpEml] = $row['Invite_ClientKey'];
			}
			mysql_free_result($res);
		}

		// do deletions - emails in xst that are not in hlist get deleted
		$dlist=array_keys($xst);
		$toProc=array_diff($dlist, $hlist); // returns elements from dlist not found in hlist
		$toAdd = array_diff($hlist, $dlist);
		/*
		$trace="curentStreamManagers() deletion list: ".print_r($toProc,true);
		$this->log2file($this->s_ServiceClass, $trace, $this->debugLog);
		*/
		if (count($toProc)) {
			foreach($toProc as $eml) {
				$kill=$xst[$eml];
				$sql="delete from InvitationList where Invite_ID='$kill'";
				mysql_query($sql,$db);
				$sql="delete from APISessions where AS_ID='$kill'";
				mysql_query($sql,$db);
			}
		}

		/* bug 3741 create Stream Manager keys immediately, rather than waiting to send email. */
		if (count($toAdd)) {
			$smList = implode(',' , $toAdd);
			$aObj = array(
				"acct" => $acct,
				"domain" => $dom,
				"meetID" => $n_MtgID,
				"emails" => $smList
			);
			$this->createStreamManagers($aObj);
		}
		return $out;
	}

	private function createIcal($startDt,$sendto,$from,$desc="") {
		// assuming scheduled date/time is YYYY-MM-DD hh:mm:ss UTC,
		// I can convert to ical format: [YYYYMMDD]T[hhmmss]Z
		$dtnow=gmdate("Ymd");
		$tmnow=gmdate("His");
		$stamp=$dtnow.'T'.$tmnow.'Z';
		$pfx=chr(rand(65,91));
		$icuid=uniqid($pfx,true);

		list($sdt,$stm)=explode(" ",$startDt);
		$p1=str_replace("-",'',$sdt);
		$p2=str_replace(":",'',$stm);
		$dtstart=$p1."T".$p2;

		$emails=explode(',',$sendto);
		$attList="ORGANIZER:MAILTO:$from\r\n";
		foreach ($emails as $cli) {
			if (strlen(trim($cli))<3) { continue; }
			$attList.="ATTENDEE;PARTSTAT=NEEDS-ACTION;RSVP=TRUE:MAILTO:$cli\r\n";
		}
		if ($desc) {
			$dxn=$this->feedIcalDesc($desc);
			$attList.="DESCRIPTION:$dxn";
		}
		// 'PRODID:-//tools.ietf.org//NONSGML iCalcreator 2.6//'."\r\n".
		$out='BEGIN:VCALENDAR'."\r\n".
		'PRODID:-//Microsoft Corporation//Outlook 11.0 MIMEDIR//EN'."\r\n".
		'VERSION:2.0'."\r\n".
		'METHOD:REQUEST'."\r\n".
		'BEGIN:VEVENT'."\r\n".
		'DTSTART:'.$dtstart."Z\r\n".
		'DTEND:'.$dtstart."Z\r\n".
		'LOCATION:Online'."\r\n".
		'TRANSP:OPAQUE'."\r\n".
		'SEQUENCE:0'."\r\n".
		'UID:'."$icuid\r\n".
		'DTSTAMP:'."$stamp\r\n".
		'SUMMARY:Video conference'."\r\n".
		'PRIORITY:5'."\r\n".
		'CLASS:PUBLIC'."\r\n".
		$attList.
		'END:VEVENT'."\r\n".
		'END:VCALENDAR'."\r\n";
		return $out;
	}

	private function createGUID() {
		// version 4 UUID
		$out=sprintf('%08x-%04x-%04x-%02x%02x-%012x',
			mt_rand(),
			mt_rand(0, 65535),
			bindec(substr_replace(
				sprintf('%016b', mt_rand(0, 65535)), '0100', 11, 4)
			),
			bindec(substr_replace(sprintf('%08b', mt_rand(0, 255)), '01', 5, 2)),
			mt_rand(0, 255),
			mt_rand()
		);
		return $out;
	}

	private function feedIcalDesc($html) {
		$pattern="/<a.*?href=[\'\"](.+?)[\'\"].*?\/a>/i";
		$rtest=preg_replace($pattern,"\n\t$1",$html);
		$rtest=preg_replace("/<ul>/i","<ul>\n",$rtest);
		$rtest=preg_replace("/<\/li>/i","</li>\n",$rtest);
		$rtest=str_replace("\r",'',$rtest);
		$txt=html_entity_decode(strip_tags($rtest));
		$txt=trim($txt);
		$txt=preg_replace("/\n{3,}/","\n\n",$txt);
		// now make sure it fits
		$lines=explode("\n",$txt);
		$txt="";
		foreach($lines as $line) {
			if ($txt) { $txt.=" "; }
			$txt.=wordwrap($line,75,"\\n\r\n ").'\n'."\r\n";
		}
		return $txt;
	}
	/**
	* Initialize a registration record.
	* Create a new registration record. This is the first part of the registration process.
	* It should occur at the same time an invitation is sent (assuming registration is required).
	* The database will not allow duplicate meetingID/email fields.
	* @param int Meeting ID.
	* @param String Email address.
	* @param String Registrant's full name.
	* @return int New registrant ID.
	*/
	private function createRegistration ($meetID, $email, $fullname="") {
		$db=$this->dbh;
		$out=0;
		$usrname="";
		if ($fullname) { $usrname=mysql_escape_string($fullname); }
		$eml=mysql_escape_string($email);
		$sql="insert into Registration Meet_ID,Reg_Name,Reg_Email,Reg_EmailSent ".
		"values('$meetID','$usrname','$eml',1)";
		if (mysql_query($sql,$db)) {
			$out=mysql_insert_id($db);
		} else {
			$err="createRegistration() failed. SQL: $sql\n".mysql_error($db);
			$this->log2file($this->s_ServiceClass,$err);
		}
		return $out;
	}

	private function groupNameByID($gid) {
		$db=$this->dbh;
		$out="";
		$sql="select DCG_Name from DepoChatGroups where DCG_ID='$gid'";
		$res=mysql_query($sql,$db);
		if ($res) {
			list($out)=mysql_fetch_array($res);
			mysql_free_result($res);
		}
		return $out;
	}

	private function getHostEmail($meetID) {
		$db=$this->dbx;
		$out="";
		$sql="select AL_Email from AccountLogins a, Meetings b where a.AL_ID=b.AL_ID && Meet_ID='$meetID'";
		$res=mysql_query($sql,$db);
		if ($res) {
			list($out)=mysql_fetch_array($res);
			mysql_free_result($res);
		}
		return $out;
	}
	private function isPrivateBranded() {
		$db=$this->dbx;
		$out=0;
		$acct=$_SESSION['acct'];
		$sql="select Acc_EnablePrivateBranded from Accounts where Acc_ID=$acct";
		$dbg="isPrivateBranded() debug. SQL: $sql";
		$this->log2file($this->s_ServiceClass,$dbg,'/var/log/httpd/amfphp_debug_log');
		$res=mysql_query($sql,$db);
		if ($res) {
			list($out)=mysql_fetch_array($res);
			mysql_free_result($res);
		}
		return $out;
	}
	private function chkMeetingConfig($updates) {
		$db=$this->dbh;
		$meetID=$updates['Meet_ID'];
		$out=false;
		$conf = array();    // account configuration
		$vpro = array();    // video profiles
		/* get account limitations
		Acc_EnableDepo
		Acc_EnableMeetings
		Acc_Seats
		Acc_MaxVideos   // from AccountSettingProfiles
		Acc_MaxAudio    // from AccountSettingProfiles
		VideoProfiles
		*/
		$alid=0;
		$sql="select a.Acc_ID, Acc_EnableDepo, Acc_EnableMeetings, Acc_Seats, b.AL_ID, Acc_MaxVideos, ".
		"Acc_MaxAudio from Accounts a, Meetings b, AccountLogins c, AccountSettingProfiles d ".
		"where a.Acc_ID=b.Acc_ID && b.AL_ID=c.AL_ID && c.Asp_ID=d.Asp_ID && Meet_ID='$meetID'";
		$res=mysql_query($sql,$db);
		if ($res) {
			$conf=mysql_fetch_assoc($res);
			$acct=$conf['Acc_ID'];
			$alid=$conf['AL_ID'];
			mysql_free_result($res);
		}
		if (!$alid) {
			if (defined("THROW_ERRORS")) { throw new Exception("Can't get account configuration."); }
			return $out;
		}

		$sql="select VProfile_ID from AL2VP where AL_ID='$alid'";
		$res=mysql_query($sql,$db);
		if ($res) {
			while (($row=mysql_fetch_array($res))!=false) { $vpro[]=$row[0]; }
			mysql_free_result($res);
		}

		// enforce - compare updates with account settings
		$ok=true;
		foreach ($updates as $prop=>$val) {
			switch ($prop) {
				case "Meet_MaxVideos":
					if ($val>$conf['Acc_MaxVideos']) { $ok=false; }
					break;
				case "Meet_MaxAudio":
					if ($val>$conf['Acc_MaxAudio']) { $ok=false; }
					break;
				case "Meet_EnableDepo":
					// Tricky because if EnableMeetings is 0, this needs to be 1.
					// But, if EnableDepo is 0, this cannot be 1.
					$x=$conf['Acc_EnableDepo'];
					$y=$conf['Acc_EnableMeetings'];

					break;
				case "Meet_Seats":
					break;
				case "Meet_VideoProfile":
					break;
			}
		}

		$out=($ok)?1:0;

		return $out;
	}

	/**
	* Get mail config for a meeting.
	* Returns an object with structure:<code>
	* EP_ID:int
	* EP_SMTPHost:String
	* EP_SMTPUsername:String
	* EP_SMTPPassword:String
	* EP_SMTPPort:int
	* EP_UseSSL:int
	* AL_UserName:String
	* AL_Email:String
	* </code>
	*
	* @param int Meeting ID
	* @return Object See description.
	*/
	private function smtpConfig($mtgID) {
		$db=$this->dbh;
		$out=false;
		if (isset($this->smtp['EP_ID'])) { return true; }
		$sql="select b.EP_ID, EP_SMTPHost, EP_SMTPUsername, EP_SMTPPassword, EP_SMTPPort, ".
		"EP_From, EP_UseSSL, AL_UserName, AL_Email from Meetings a, AccountLogins b left join ".
		"EmailProfiles c on b.EP_ID=c.EP_ID where a.AL_ID=b.AL_ID && Meet_ID=$mtgID";
		$this->log2file($this->s_ServiceClass,"smtpConfig() debug. $sql");
		$res=mysql_query($sql,$db);
		if ($res) {
			$row=mysql_fetch_assoc($res);
			mysql_free_result($res);
			$out=true;
			$this->smtp=$row;
		} else {
			$err="smtpConfig() failed. ".mysql_error();
			$this->log2file($this->s_ServiceClass, $err);
		}
		return $out;
	}
	/**
	* Use external SMTP server to send mail.
	* Intended to be used like the standard mail() function, but
	* requires a configuration object.
	*
	* @param Object Configuration object.
	* @param String Recipient email.
	* @param String Email subject.
	* @param String Message body.
	* @param String Email headers.
	* @return int 1=success, 0=failed.
	*/
	private function extMail($rcpt, $subj, $body, $headers) {
		$out=0;
		$config=$this->smtp;    // set when smtpConfig was called.
		$ignore=array("reply-to","from","to","subject");
		$mhost=$config['EP_SMTPHost'];
		// figure out domain
		$ix=strpos($mhost,'.') + 1;
		$domain=substr($mhost,$ix);
		$from=$config['EP_SMTPUsername'];
		// JC - Bug #1760, request to no longer append @domain to user names
		//if (strpos($from,'@')===false) { $from.="\@$domain"; } //add @domain to username if nec.
		// create header object from 'headers' param

		// 29 Aug 2013 Virgil S. Palitang
		// Bug 2435 - Need to rewrite the From header, and set the reply-to header.
		$rpl2=$config['AL_Email'];
		$epFrom=$config['EP_From'];
		if (strpos($epFrom,'<')===false) { $superFrom = '"'.$config['AL_UserName']."\" <$epFrom>"; }
		else { $superFrom = $epFrom; }

		$headers=trim($headers);
		$hdlist=explode("\n",$headers);
		$hdrs=array(
			"From"=>$superFrom,
			"Reply-to" => $rpl2,
			"To"=>$rcpt,
			"Subject"=>$subj
		);
		foreach ($hdlist as $ele) {
			list ($key, $val) = explode(":",$ele,2);
			$lc=strtolower($key);
			if (in_array($lc,$ignore)) { continue; }
			$sv=trim($val);
			$hdrs[$key]=$sv;
		}
		// add other header data to help reduce spam score
		$hdrs['Date']=date("r");
		$hdrs['Message-ID']="<".$this->createGUID().'@'.$domain.">";
		// prep message using config
		$hhh=print_r($hdrs,true);
		// $dbg="extMail() debug.\n--- $headers\n--- $hhh\n$body";
		// $this->logDebug($dbg);
		$host="";
		if ($config['EP_UseSSL']) { $host.="ssl://"; }
		$host.=$mhost;
		$port=$config['EP_SMTPPort'];
		$user=$config['EP_SMTPUsername'];
		$pass=$config['EP_SMTPPassword'];
		$smtp = Mail::factory('smtp',array(
			"host"=>$host,
			"port"=>$port,
			"auth"=>true,
			"username"=>$user,
			"password"=>$pass)
		);
		// SEND IT!!!
		$mail = $smtp->send($rcpt, $hdrs, $body);
		if (PEAR::isError($mail)) {
			$err="extMail() error. ".$mail->getMessage();
			$this->log2file($this->s_ServiceClass,$err);
		} else {
			$out=1;
		}
		return $out;
	}

	private function needCredMgr($eml) {
		$db=$this->dbh;
		$out=0;
		$email=mysql_escape_string($eml);
		$sql="select count(*) from CredentialManager where CM_Email='$email'";
		$res=mysql_query($sql,$db);
		if ($res) {
			$row=mysql_fetch_array($res);
			mysql_free_result($res);
			if ($row[0]<1) { $out=1; }
		}
		return $out;
	}

	private function getRegPageStyle($acct, $alid, $lgid) {
		$db=$this->dbh;
		$out=0;

		// find existing record
		$sql="select RPS_ID from RegPageStyle where Acc_ID='$acct' && AL_ID='$alid' && Logo_ID='$lgid'";
		$res=mysql_query($sql,$db);
		if ($res) {
			list ($out)=mysql_fetch_array($res);
			mysql_free_result($res);
		} else {
			$out=0;
			$err="getRegPageStyle() - could not execute query. ".mysql_error();
			$this->log2file($this->s_ServiceClass,$err);
			return $out;
		}

		// check for usable value
		if (!$out) {
			// create a new one
			$sql="insert into RegPageStyle (Acc_ID, AL_ID, Logo_ID) values ('$acct','$alid','$lgid')";
			if (mysql_query($sql,$db)) { $out=mysql_insert_id($db); }
			else {
				$out=0;
				$err="getRegPageStyle() - could not create new record. ".mysql_error();
				$this->log2file($this->s_ServiceClass,$err);
			}
		}
		return $out;
	}
	private function emailTrack($mtg, $eml, $domain="") {
		if (strpos(MAILDOMAIN, "conferencinginfo")!==false) {
			$mlx = "pb.videoconferencinginfo.com";
		}
		elseif (strpos(MAILDOMAIN, "bridelive")!==false) {
			$mlx = "lc3.livecloudevents.com";
		}
		elseif (strpos(MAILDOMAIN, "livedeposition")!==false) {
			$mlx = "simple.livedeposition.com";
		}
		else {
			$mlx = "applax01.megameeting.com";
		}
		$dom = ($domain)? $domain : $mlx;
		$sentKey=base64_encode("$mtg:$eml");
		$img="http://$dom/rx.php?id=$sentKey";
		$out="<div style=\"visibility:hidden; display:none\"><img src=\"$img\"></div>";
		return $out;
	}
	private function logSoapErr($method, SoapFault $exception) {
		$msg = $exception->getMessage();
		$trace = "$method SOAP error. $msg";
		$this->log2file($this->s_ServiceClass, $trace);
	}

	private function logDebug($msg) {
		$this->log2file($this->s_ServiceClass,$msg,"/var/log/httpd/amfphp_debug_log");
	}
}
?>
