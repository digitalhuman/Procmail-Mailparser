<?php
/**
 * Description of mail_parser
 * 
 * @author Victor Angelier <vangelier@hotmail.com>
 * @copyright 2014 Ignite the Future
 * @dependencies php-pecl-mailparse, php-mbstring
 */

/**
 * Install howto
 * 
 * Create 'procmailrc' file in /etc/
 * Add the following lines
 *      :0c
 *       | /usr/bin/php /usr/local/bin/mailparser.php
 * 
 * Create: /usr/local/bin/mailparser.php
 * 
<?php
require_once('mail_parser.php');
 
$rawEmail = "";
if (($fp = fopen('php://stdin', 'r')) !== false) {
    while (!feof($fp)) {
        $rawEmail .= fread($fp, 1024);
    }
    fclose($fp);
}

$c = new mail_parser($rawEmail);
$c->parse(); // <== do something good with the output

?>
 */
class mail_parser {
    
    //Temporary filename
    protected $temp_filename = null;
    //Raw file data
    protected $raw_data = null;
    
    //Mime message
    protected $message = null;
    //Temp dir
    public $tempdir = "/tmp/";
    //Location where to save attachements
    public $attachment_store = "/home/mailparser/attachments/";
    
    public function __construct($raw_mail_message = "") {
        if($this->message == null){
            
            //Set raw message data
            $this->raw_data = $raw_mail_message;
            
            //Set temporary filename
            $this->temp_filename = md5(rand(1,2342423423));
            
            //Write down the mail message
            if((file_put_contents($this->tempdir.$this->temp_filename, $raw_mail_message)) == false){
                echo "Cannot write to {$this->tempdir}{$this->temp_filename}";
                exit(0);
            }else{
                if(($this->message = mailparse_msg_parse_file($this->tempdir . $this->temp_filename)) !== false){
                }else{
                    echo "Erro parsing file";
                    exit(0);
                }
            }
        }        
    }
    
    /**
     * Parse the mail
     * 
     * @return array
     */
    public function parse(){
        $from = $this->get_from();
        $to = $this->get_to();
        $gpgkey = $this->get_gpg($to[0]["address"]);
        
        $result = array(
            "from" => $from,
            "to" => $to,
            "cc" => $this->get_cc(),
            "datetime" => $this->get_datetime(),
            "subject" => $this->get_subject(),
            "body" => $this->get_text_body(),
            "gpg_pub_key" => $gpgkey,
            "attachments" => $this->save_attachments()
        );
        return $result;
    }
    
    /**
     * Get the mail's date and time (Dutch style)
     * @return type
     */
    private function get_datetime(){
        $parts = mailparse_msg_get_part_data($this->message);
        return date("d-m-Y H:i", strtotime($parts['headers']['date']));
    }
    
    /**
     * Get the subject of the mail message
     * @return type
     */
    private function get_subject(){
        $parts = mailparse_msg_get_part_data($this->message);
        return $parts['headers']['subject'];
    }
    
    /**
     * Method to save attachments
     * @return bool
     */
    private function save_attachments(){
        $result = array();
        
        //Loop through all files;
        foreach($this->get_message_structure() as $file){
            $parts = explode(".", $file);
            if(count($parts) == 2 && $parts[1] >= 2){
                //Attachement part
                $mime_part = mailparse_msg_get_part($this->message, "{$file}");        
                $body_parts = mailparse_msg_get_part_data($mime_part); 
                
                //Check if we have files
                if(isset($body_parts['disposition-filename'])){
                    $filename = $body_parts['disposition-filename'];

                    if(file_put_contents($this->attachment_store.$filename, base64_decode(substr($this->raw_data, $body_parts['starting-pos-body'], $body_parts['ending-pos-body']))) !== false){
                        array_push($result, array(
                                    "content_type" => $body_parts['content-type'],
                                    "full_path" => $this->attachment_store.$filename,
                                    "filename" => $filename,
                                    "checksum" => sha1_file($this->attachment_store.$filename)
                                )
                        );
                    }
                }
            }
        }
        if(count($result) > 0){
            return $result;
        }else{
            return false;
        }
    }
    
    /**
     * Get the ASCII version of the body
     * @return string
     */
    private function get_text_body(){
        //Mime 1.1.1 OR 1.1
        if(($txt_body = mailparse_msg_get_part($this->message, "1.1.1")) !== false){
            $body_parts = mailparse_msg_get_part_data($txt_body);

            $body = substr($this->raw_data, $body_parts['starting-pos-body'], $body_parts['ending-pos-body']);        
            $raw = quoted_printable_decode($body);
            $lines = explode("--", $raw);
            return $lines[0]; //This is always the text/plain body
        }else{
            if(($txt_body = mailparse_msg_get_part($this->message, "1.1")) !== false){
                $body_parts = mailparse_msg_get_part_data($txt_body);

                $body = substr($this->raw_data, $body_parts['starting-pos-body'], $body_parts['ending-pos-body']);        
                $raw = quoted_printable_decode($body);
                $lines = explode("--", $raw);
                return $lines[0]; //This is always the text/plain body
            }else{
                if(($txt_body = mailparse_msg_get_part($this->message, "1")) !== false){
                    $body_parts = mailparse_msg_get_part_data($txt_body);

                    $body = substr($this->raw_data, $body_parts['starting-pos-body'], $body_parts['ending-pos-body']);        
                    $raw = quoted_printable_decode($body);
                    $lines = explode("--", $raw);
                    return $lines[0]; //This is always the text/plain body
                }else{
                    return "Could not get body.";
                }                
            }
        }
        return "Could not get body.";
    }
    
    /**
     * Returns all mime parts of this message
     * @return array
     */
    private function get_message_structure(){
        return mailparse_msg_get_structure($this->message);
    }
    
    /**
     * Get the CC part of the mail (name and e-mail address)
     * @return type
     */
    public function get_cc(){
        $parts = mailparse_msg_get_part_data($this->message);
        if(isset($parts['headers']['cc'])){
            return mailparse_rfc822_parse_addresses($parts['headers']['cc']);               
        }
    }
    
    /**
     * Get the FROM part of the mail (name and e-mail address)
     * @return type
     */
    public function get_from(){
        $parts = mailparse_msg_get_part_data($this->message);
        return mailparse_rfc822_parse_addresses($parts['headers']['from']);               
    }
    
    /**
     * Get the TO part of the mail (name and e-mail address)
     * @return type
     */
    public function get_to(){
        $parts = mailparse_msg_get_part_data($this->message);
        return mailparse_rfc822_parse_addresses($parts['headers']['to']);
    }
    
    /**
     * Get the users GPG Public Key
     * @param string $email
     */
    public function get_gpg($email = ""){
        if($email != ""){
            if(($xml = @file_get_contents("https://pgp.mit.edu/pks/lookup?op=vindex&search={$email}")) !== false){
                if(preg_match_all("/\<a href=\".*\"?+\>/", $xml, $matches) !== false){
                    if(is_array($matches[0]) && count($matches[0]) > 0){

                        $valid_date = "";
                        $link = "";

                        foreach($matches[0] as $match){
                            $parts = explode("</a>", $match);

                            //Check for dates and valid until date
                            preg_match_all("/([0-9]{4}\-[0-9]{2}\-[0-9]{2})/", $match, $dates);
                            if(is_array($dates[1]) && count($dates[1]) > 1){

                                //Check validity
                                if(strtotime(trim($dates[1][1])) > time()){

                                    $valid_date = strtotime(trim($dates[1][1]));
                                    //We found the right key;
                                    preg_match("/href=\".*\"/", $parts[0], $match2);
                                    if(is_array($match2) && count($match2) > 0){
                                        $link = $match2[0];
                                        break;
                                    }
                                }

                            }

                        }
                        if($link != ""){
                            //We have matches
                            $link = str_replace(array(
                                "href=",
                                "\""
                            ), "", $link); //We only want the 1st one
                            $link = html_entity_decode($link);        
                            if(($key = @file_get_contents("https://pgp.mit.edu".$link)) !== false){
                                $gpg_pub_key = substr($key, strpos($key, "-----BEGIN PGP PUBLIC KEY BLOCK-----"), (strlen($key)-strpos($key, "-----END PGP PUBLIC KEY BLOCK-----
                    ")));                        
                                return array(
                                    "key" => trim(strip_tags($gpg_pub_key)),
                                    "date" => date("Y-m-d", $valid_date)
                                );
                            }
                        }
                    }
                }
            }
        }
        return false;
    }
}