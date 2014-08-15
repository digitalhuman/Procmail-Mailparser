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
     */
    public function parse(){
        $result = array(
            "from" => $this->get_from(),
            "to" => $this->get_to(),
            "cc" => $this->get_cc(),
            "datetime" => $this->get_datetime(),
            "subject" => $this->get_subject(),
            "body" => $this->get_text_body(),
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
                        array_push($result, $this->attachment_store.$filename);
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
        //Mime 1.1.1 == Text Body
        if(($txt_body = mailparse_msg_get_part($this->message, "1.1")) !== false){
            $body_parts = mailparse_msg_get_part_data($txt_body);

            $body = substr($this->raw_data, $body_parts['starting-pos-body'], $body_parts['ending-pos-body']);        
            $raw = quoted_printable_decode($body);
            $lines = explode("\n", $raw);
            $body = "";
            foreach($lines as $line){       
                if(preg_match("/(--_.*_$)/", $line) > 0){
                    //New mime part found.
                    break;
                }else{
                    $body .= htmlspecialchars_decode(str_replace("\n", "\r\n", $line))."\r\n";
                }
            }
            return nl2br($body);
        }else{
            return "Could not get body.";
        }
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
}