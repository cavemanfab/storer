 ********************************************
 * Automatic archiving of email attachments *
 ********************************************

PURPOSE:
having a quick and friendly way to store files in your synology nas when you're away.
The interface is email.
You send an email with one or more attachments, and this script extracts and stores them in a predetermined
folder; you can specify a subfolder.
The subject contains the keywords to determining the destination folder. Alternatively, you can leave 
the subject blank and have the script take the keyword from the recipient's address.

**  Determining the destination in the file system
Sending to a preconfigured email address, the email is received but not sent to a mailbox; it's passed to a script.
A short name is read from the first word of the subject.
This short name will be expanded into a foldername via a lookup table (destlut.text); the rest of the subject
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
Setting the subject to "accounting invoices/2024" directs attachments to /volume1/work/fabio/invoices/2024
Omitting the subject makes the scripts read the "to:" header of the email, let's suppose it's
  attachs.archive@autostore.com, filter it via the regex specified in the line starting with "-
  and taking the 1st capture group, that turns out to be "attachs". This value will be the key for a lookup search,
  obtaining  /volume1/attached files

** Possible problems & debug

The script exits with a result code:
ERR_NOERR             0  No error
ERR_NODEST            1  Cannot find a key to determine destination
ERR_NOLUT             2  Cannot find destlut.text
ERR_NOTINLUT          3  Key not found in destlut.text
ERR_WRONGTO           4  Recipient not responding to recipient's schema (as of now, cannot happen)
ERR_NOSTREAM          5  No input stream to process
ERR_CANTMAKEPATH      6  Cannot create destination folder (permissions problem?)
ERR_CANTOPENFILE      7  Cannot open output stream
ERR_CANTWRITEFILE     8  Cannot wite to output stream


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
