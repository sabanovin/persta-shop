<?php
/*
  $Id: sms.php $
   opncart Open Source Shopping Cart Solutions
  http://www.opencart-ir.com
  version:2.8
*/


 final class Sms {
      private     $to;
      private   $body;
      private $username ;
	  private $sms_api ;
	  
      private  $from;
	
      public $flash = false;
 
    function send_sms($from,$to_mobile_number,$sms_text,$sms_api) {
		
      if ( (!empty($to_mobile_number)) && 11 <= strlen( $to_mobile_number ) && substr($to_mobile_number,0,2)=="09" ||  substr($to_mobile_number,0,4)=="+989" ) {
      
		return $this->send($to_mobile_number,$sms_text,$sms_api,$from);
		 }
    }

    function send($to,$body,$sms_api,$from) {
		
		 $this->sms_api=$sms_api;
	
	//$to=$this->session->data['to'];	
	$this->to=$to;
	//$body=$this->session->data['sms_text'];
	$this->body=$body;
	
		
			
			        $SendUrl=" ?gateway=".$from."&amp;to=".$to."&amp;text=" . urlencode($body) . "";
					
					$url = "https://api.sabanovin.com/v1/".$this->sms_api."/sms/send.json";

// http_build_query builds the query from an array
					$query_array = array (
						'text' => $body,
						'gateway' => $from,
						'to' => $to,
						'format' => 'json'
					);

					$query = http_build_query($query_array);
					$result = file_get_contents($url . '?' . $query);
					
				
	}
   
	////////////////////////////////////////////////send sms sabapayamak//////////////////////////////////////////////////////
	
	
	
	

	
  }
  
?>
