This is a port of Proxy Revealer 2.0.1 MOD (phpBB2) to phpBB3. Attempts to determine someone's "real" IP address, using a myriad of techniques, and "blocks" such people. Original techniques included XSS, Java, and X\_FORWARDED\_FOR checks. In this port, Flash has been added as yet another unmasking technique. There maybe additional techniques added on later.


Features:

  * HTTP(S)/SOCKS Proxy Detection by Flash and Java applet techniques, and optional blocking.
  * Optional blocking of transparent HTTP Proxies detected with X\_FORWARDED\_FOR technique.
  * CGI-Proxy Detection by XSS and Flash techniques, and optional blocking.
  * Optional auto-banning of unmasked IP addresses with the chosen blocking techniques above.
  * Blocking/Banning done within the confines of phpBB3's "Session IP Validation" setting in the ACP.
  * Auto-Logging masked/unmasked IP addresses with possible extended info if Flash/Java was used.
  * Optional Exception List Management for excluding particular proxy servers you may be running.

Project's Home and discussion thread:
http://www.phpbb.com/community/viewtopic.php?f=70&t=1174765&start=0