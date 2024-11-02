#!/usr/local/bin/php73
<?php
/********************************************
 * Automatic archiving of email attachments *
 ********************************************

**  Determining the destination in the file system
Sending to a preconfigured email address, a short name is read from the first word of the subject.
This short name will be expanded into a foldername via a lookup table; the rest of the subject
is read as a subfolder and appended to the result of the lookup. A default can be specified, filtering
the recipient's name via a regex and using the 1st capture group against the lookup table.

** Body
The body is read but, to date, not used.

** Attachments
Multiple attachments can be placed.

** Output files
The name of the output file(s) is like the attachment's one.
Homonyms are renamed appending a " (sequential)" to the file name, before the extension, if any. 

** Mail server setup
Install Synology Mail Server
Setup the domain pointing to your NAS:
 - MX record
 - SPF and DKIM, if possible.

Add a transport to /var/packages/MailServer/target/etc/master.cf like this:
---
archiv   unix  -       n       n       -      50       pipe
    flags=R user=USERNAME argv=FULLSCRIPTNAME -o SENDER=${sender} -m USER=${user} EXTENSION=${extension}/
---
e.g.
----
archiv   unix  -       n       n       -      50       pipe
    flags=R user=administrator argv=/var/packages/MailServer/target/mystuff/archiveattach.php -o SENDER=${sender} -m USER=${user} EXTENSION=${extension}/
----
Add a transport table to /var/packages/MailServer/target/etc/main.cf
(for the correct place to put it, search TRANSPORT MAP)
add a line line this:
----
transport_maps = regexp:/volume1/@appstore/MailServer/etc/redirect.regexp
----
where "redirect.regexp" is a name of your choice.


In redirect.regexp specify the regex to match the to: address and the corresponding transport as specified in master.cf:
----
/^.+\.archive@autostore\.com/      archiv:
----
Adapt to your domain and addressing scheme

When done, run 
----
/var/packages/MailServer/target/sbin/postfix reload
----

Create the users that will receive this kind of email (blabla.archive in these examples)

** Lookup table example

---
fabio /volume1/work/fabio
accounting /volume2/management/accounting
attachs /volume1/attached files
- (.+)\.archive@autostore\.com
---

Setting the subject to “fabio” in subject directs attachments to /volume1/work/fabio
Setting the subject to "accounting invoices/2024 directs attachments to /volume1/work/fabio/invoices/2024
Omitting the subject makes the scripts read the "to:" header of the email, let's suppose it's
  attachs.archive@autostore.com, filter it via the regex specified in the line starting with "-",
  and taking the 1st capture group, that turns out to be "attachs". This value will be the key for a lookup search,
  obtaining  /volume1/attached files

** Possible problems & debug

The script exits with different codes -- see the defines at the beginning

- software bugs (forever)
- unmanaged syntax coming from servers' mail handling (I tested with outlook, thunderbird, gmail)
- unmanaged events (missing files or folders, errors in lookup table...)
- permission problems of the user specified in the transport (master.cf)
- if in the script's folder there is a file named:
	- debug.flag
		a debug file named scambio/f_debug.txt will be appended with new debug messages
		(suggestion: link "scambio" folder to somewhere you can reach via File Manager)
	- debug.flag.TTY
		debug messages will be echoed to console
*/

define ("QUERYERR",             -1); // must be less than other ERR values
define ("ERR_NOERR",             0);
define ("ERR_NODEST",            1);
define ("ERR_NOLUT",             2);
define ("ERR_NOTINLUT",          3);
define ("ERR_WRONGTO",           4);
define ("ERR_NOSTREAM",          5);
define ("ERR_CANTMAKEPATH",      6);
define ("ERR_CANTOPENFILE",      7);
define ("ERR_CANTWRITEFILE",     8);



function curdir() {
	return substr($_SERVER["SCRIPT_FILENAME"], 0, (strrpos($_SERVER["SCRIPT_FILENAME"], "/") ?: -1)+1);
}

	function fleggi($h) {
	global $inputbuffer;
	if ($inputbuffer === NULL) {
		return rtrim(fgets($h));
	} else {
		$t = $inputbuffer;
		$inputbuffer = NULL;
		return $t;
	}
}
function fleggihdr($h) {
	global $inputbuffer;
	$p = fleggi($h);
	while (! feof($h)) {
		$inputbuffer = rtrim(fgets($h));
		switch (substr($inputbuffer, 0, 1)) {
			case "\t":
				$inputbuffer = substr($inputbuffer, 1);
				// no break please!!!
			case " ":
				$p .= $inputbuffer;
				break;
			default:
				break 2;
		}
	}
	return $p;
}

if (file_exists(curdir() . "debug.flag")) {
	$debugmsg = function ($towrite) { 
		static $debugh = -1;
		if ($towrite == "CLOSE STREAM") {
			if ($debugh != -1) fclose($debugh);
		} else {
			if ($debugh == -1) $debugh = fopen(curdir() . "scambio/f_debug.txt", "a");
			fwrite($debugh, date("Y-m-d H:i:s") . " $towrite\n");
		}
	 };
} elseif (file_exists(curdir() . "debug.flag.TTY")) {
	$debugmsg = function ($towrite) {echo $towrite ."\n";};
} else {
	$debugmsg = function ($towrite) {};
}

function whaterr($setorqry) {
	global $debugmsg;
	static $ERRORE = ERR_NOERR;
	if ($setorqry == QUERYERR) {
		// $debugmsg("Query--returning $ERRORE");
		return $ERRORE;
	} else {
		// $debugmsg("Setting error to $setorqry");
		return ($ERRORE = $setorqry);
	}
}

class MailMessageClass {
	// MailMessageClass:
	// 	Object Properties:
	// 		subject					string
	// 		data					date epoch ("date" header in message)
	// 		bodylines				array of text
	// 		ishtm					boolean (set to true if message is in html format)
	// 		from					string (sender's email address)
	// 	methods:
	// 		readheaders(<name of file containing message>)
	// 			Legge il messaggio e compila le proprieta') {
	// Discard text, look for 2nd part where an attach is
	//	start reading up to 
	public $subject;
	public $datim;
	public $bodylines;
	public $ishtml;
	public $from;
	public $boundary;
	function readheaders($handle) {
		global $debugmsg;		
		$this->ishtml = false;
		$inSubject = false;
		$this->subject = "";
		$boundary="";	// per i msg con txt e html
		while ($linea = fleggihdr($handle)) {
			$debugmsg("readheaders:$linea");
			switch (substr($linea,0,1)) {
				 case " ":
				 	switch ($hdr) {
				 		case "subject":
				 			$tmpsubject = ltrim($linea);
				 			if (preg_match("%=\?[A-Za-z\-0-9]+\?[Qq]\?(.*)\?=$%", $tmpsubject, $b64))
				 				$this->subject .= str_replace("_", " ", $b64[1]);
				 			break;
				 		default:
				 			// aggiungere ev altre cose da gestire multilinea
				 			break;
				 	}
				 case "\t":
				 	break;
				case "":
					break 2;	// quando arriva a una riga vuota l'header e' finito e passa a leggere il corpo msg
				default:
					$hdr = strtolower(strstr($linea, ":", true));
					switch ($hdr) {
						case "subject":
							$tmpsubject = substr(strstr($linea, ":"), 2);
							if (preg_match("%.*=\?UTF-8\?[Bb]\?([A-Za-z0-9/+]+={0,2})\?=$%", $tmpsubject, $b64))
								$this->subject = base64_decode($b64[1]);
							elseif (preg_match("%.*=\?[A-Za-z\-0-9]+\?[Qq]\?(.*)\?=$%", $tmpsubject, $b64))
								$this->subject = str_replace("_", " ", $b64[1]);
							else
								$this->subject = $tmpsubject;
							break;
						case "date":
							$this->datim = strtotime(substr(strstr($linea, ":"), 2));
							break;
						case "content-type":
							if (preg_match("/content-type ?: ?text\/html.*/i", $linea) == 1) {
								$this->ishtml = true;
							} elseif (preg_match("/content-type ?: ?multipart.mixed.*boundary=\"(.*)\"/i", $linea, $boundId) == 1) {
								$this->boundary = $boundId[1];
								unset ($boundId);
								$this->ishtml = true;
							}
							break;
						case "from":
							if (preg_match("/.*<(.*)>/", $linea, $pezzi)) $this->from = $pezzi[1];
							break;
						case "to":
							/*
							// con syno non si riesce a mettere nel To un qualcosa per indirizzare
							// al posto giusto il file. Questo era il codice per farlo, va spostato nella gestione
							// del subject o del corpo
							$debugmsg("cerco il TO dentro a $linea");
							if (preg_match("/to:(?:.*<| *)(.*)\.archive@casa\.manera\.biz/i", $linea, $tolookup)) { // tolookup(1) e' quello da cercare
								$debugmsg("$tolookup[0] risponde alle richieste con $tolookup[1]");
								if ($hLut = fopen(curdir() . "destlut.text", "r")) {
									$this->destpath = "";
									while ( ! feof($hLut) && $this->destpath == "") {
										$couple = rtrim(fgets($hLut));
										if (substr($couple, 0, 1) != "#") {
											// $debugmsg ("   ho letto $couple");
											$cpl = explode(" ", $couple, 2);
											if ($cpl && count($cpl) == 2) if (strcasecmp($cpl[0], $tolookup[1]) == 0) $this->destpath = $cpl[1];
										}
									}
									fclose ($hLut);
								} else {
									$debugmsg("$tolookup[1] non e' nella tabella lut");
									whaterr (ERR_NOLUT);
								}
							} else {
								$debugmsg("indirizzata alla casella sbagliata <$linea>");
								whaterr (ERR_WRONGTO);
							}
							*/
							if (preg_match("/to:(.*<| *)([^>]+)/i", $linea, $tolookup)) $this->to = $tolookup[2];
							/*
							  Wanna use the disply name to determine the key for destlut.text? Work on
							  $tolookup[1]. Warning, could be empty: if you address to "Doe, John<johndoe@nowhere.us>
							  you'll find <blank space>"Doe, John" in $tolookup[1], including the quotes.
							  If you just send to johndoe@nowhere.us it will be empty.
							*/
							break;
					}
			}
		}
	}
}


function manageOutputTo($thepath, $thefile, &$thehdl, $thebuff) {
	global $msg;
	global $debugmsg;
	if (strlen($thebuff) > 0) {
		if ($thehdl < 0) {
			/* $fnpos = strrpos($thepath, "/");
			if ($fnpos == false) {
				$fnpos = -1;
				$path = "/var/services/homes";
			} else {
				$path = substr($thepath, 0, $fnpos);
				if (substr($path, 0, 1) != "/") $path = "/var/services/homes/" . $path;
			} */
			if ($thepath && (substr($thepath, -1) !== "/")) $thepath = $thepath . "/";
			$thefile = $thepath . $thefile;
			$debugmsg ("path e file = <$thepath> <$thefile>");
			if (! is_dir($thepath) ) {
				if ( ! mkdir($thepath, 0777, true) ) {
					whaterr (ERR_CANTMAKEPATH);
					return ;
				}
			} else {
				if (file_exists($thefile)) {
					if (strtolower($msg->subject) != "overwrite") {
						$extpos = strrpos($thefile, ".");
						$recount = 0;
						do {
							$recount++;
							$recountstr = " ($recount)";
							if ($extpos) 
								$try = substr($thefile, 0, $extpos) . $recountstr . substr($thefile, $extpos); 
							else 
								$try = $thefile . $recountstr;
						} while (file_exists($try));
						$thefile = $try;
					}
				}
			}
			if (! $thehdl = fopen($thefile, "w")) {
				whaterr (ERR_CANTOPENFILE);
			}
		}
		if ($thehdl)
			if (! fwrite($thehdl, base64_decode($thebuff)))
				whaterr (ERR_CANTWRITEFILE);
	}
}

// See what options are passed in and absorb them:
$opts=getopt('e:f:t:h');
// Get the message from stdin

if (file_exists(".vscode/launch.jsonz")) // if in develop environment
	$msghdl = fopen("atxtmessage", "r"); 
else 
	$msghdl = fopen("php://stdin", "r");

if ( $msghdl === false ) {
	$debugmsg ("cannot open stdin");
	exit(ERR_NOSTREAM);
}
$msg = new MailMessageClass();
$msg->readheaders($msghdl);		// reads the message 
$debugmsg("letti tutti gli headers, errore finale:" . whaterr(QUERYERR));
$errnum = whaterr(QUERYERR);
if ($errnum != ERR_NOERR) {
	$debugmsg ("Errore $errnum, esco");
	exit ($errnum);
} else {
	/**
	   Reading destination lookup table
	 */
	$debugmsg("Leggo la tabella destlut.text");
	if ($hLut = fopen(curdir() . "destlut.text", "r")) {
		while ( ! feof($hLut) ) {  //  loading array[short]=path
			$couple = rtrim(fgets($hLut));
			if (substr($couple, 0, 1) != "#" && ($couple != "")) {
				// $debugmsg ("   read $couple");
				if (preg_match("/^([^ ]+) +(.+)/", $couple, $cpl))
					if ($cpl && count($cpl) == 3) 
						$destlut[ strtolower($cpl[1])] = $cpl[2];
			}
		}
		fclose ($hLut);
	} else {
		$debugmsg("Cannot open lookup table");
		exit (ERR_NOLUT);
	}
	$debugmsg("Determining output folder");
	/**
	 	Determining output folder
	 * based on subject as recipient, if needed
	 */
	if (! preg_match("/ *([^ ]+) *(.*)/", $msg->subject, $pathbld)) { // if subj is empty switch to default
		if (array_key_exists("-", $destlut))  // subj empty but there is a default
			if (array_key_exists("-", $destlut) && preg_match("/" . $destlut["-"] . "/", $msg->to, $basedest))
				// if a default exists and matches to:
				// example:
				// to: gino.arch@pippo.pip, filter via regex ^(.+)\.arch@pippo\.pip$, in $basedest[1] there is gino
				$basedest[1]=strtolower($basedest[1]);
				if (array_key_exists($basedest[1], $destlut))  // does destlut[gino] exists?
					$destpath = str_replace("//", "/", $destlut[$basedest[1]] . "/" . $pathbld[2]);
		// if subject="" and default ok, we have destpath
	} else {
		// subj not empty, lookup in table
		// in pathbld you find [1]short [2]subfolders, if any
		$pathbld[1]=strtolower($pathbld[1]);
		if (array_key_exists($pathbld[1], $destlut)) // if the 1st word of the subject is an index of the lookup table
			$destpath = str_replace("//", "/", $destlut[$pathbld[1]] . "/" . $pathbld[2]);
		else {
			$debugmsg("Unexisting short: $pathbld[1]");
			exit (ERR_NOTINLUT);
		}
	}

	/** 
	 * 
	 end of useful headers, skipping up to headers' end
	**/
	$debugmsg ("Looking for boundary before body...");
	while (!feof($msghdl)) {	// cLooking for boundary before body
		$linea = fleggi($msghdl);
		$debugmsg ("     letta $linea");
		if (preg_match("/-*$msg->boundary-*/", $linea) == 1) break;
	}
	/**
	Reading email body
		- other headers
		- a blank line
		- body message until a new boundary occurrence
	**/
	$debugmsg ("Looking for boundary marker before attach, and storing text (for future expansions)");
	$inbody = false;
	while (!feof($msghdl)) {	// Looking for boundary marker before attach
		$linea = fleggihdr($msghdl);
		$debugmsg ("     letta $linea");
		if (preg_match("/-*$msg->boundary-*/", $linea) == 1) break;
		if ($inbody) $msg->bodylines[] = $linea; else $inbody = $linea == "";		
	}
	/**
	 from here there are blocks [hdrs/a blankline/attach B64/boundary]
	**/
	while (!feof($msghdl)) {	// looking for [hdrs/blankline/attach/boundary]
		$filename = "";
		$debugmsg ("reading headers before attach");
		while (!feof($msghdl)) {		// reading headers before attach
			$linea = fleggihdr($msghdl);
			// $debugmsg ("	looking for hdrs, I read $linea");
			if ($linea == "") break;
			$hdr = explode(":", $linea, 2);
			if (strtolower($hdr[0]) == "content-disposition") {
				$debugmsg ("found the place where filename is");
				$args = explode("filename=", $hdr[1]);
				if ($args[1] && (stripos($args[0], "attachment") >= 0)) {
					$filename=$args[1];
					$debugmsg ("    found filename $filename");
					switch (substr($filename, 0, 1)) {
						case "\"":
						case "'" :
							$filename = substr($filename, 1, -1);
							break;
					}
				}
			}
		}
		/**
		 Reading the attach in ~4K blocks as somebody tolsetorqryd about problems
		 with base64_decode (https://www.php.net/manual/en/function.base64-decode.php)
		 Writing directly to destination file, as a preceding version would be renamed
		 in manageOutputTo
		 */
		$buff = "";
		$outhdl = -1;
		$debugmsg ("output to $destpath/$filename"); 
		while (!feof($msghdl) && $filename) {
			$linea = fleggi($msghdl);
			if (preg_match("/^-*$msg->boundary-*$/", $linea) == 1) {
				manageOutputTo ($destpath, $filename, $outhdl, $buff);
				if (whaterr(QUERYERR) != ERR_NOERR) exit (whaterr(QUERYERR));
				$debugmsg ("I read <$linea>, end of attach. Writing ". strlen($buff) . " bytes");
				break;
			} else {
				$buff .= $linea;
				if (strlen($buff) > 4500 ) {
					if (($modulo = strlen($buff) % 4) == 0) {
						$tmpbuff = "";
					} else {
						$tmpbuff = substr($buff, -$modulo);
						$buff = substr($buff, strlen($buff) - $modulo);
					}
					manageOutputTo ($destpath, $filename, $outhdl, $buff);
					if (whaterr(QUERYERR) != ERR_NOERR) exit (whaterr(QUERYERR));
					$debugmsg ("Writing ". strlen($buff) . " bytes");
					$buff = $tmpbuff;
				}
			}
		}
		if ($outhdl != -1) fclose ($outhdl);
	}
}
fclose ($msghdl);
