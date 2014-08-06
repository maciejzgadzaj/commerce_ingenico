DESCRIPTION
===========
Ogone (http://www.ogone.com) integration for the Drupal Commerce payment and checkout system.

INSTALLATION INSTRUCTIONS FOR COMMERCE OGONE
============================================

First you should make sure you have an Ogone Merchant account, and are ready to configure it:

Configuring Ogone
------------------
- Log in with your Ogone merchant account to the test or prod environment
  (depending on which you'd like to configure)
- First of all the merchant needs to create a special API user that will be used for the  api calls in Drupal, to do that go to Configuration-> Users.
  Click on the button ‘New user’, enter the details, and select the checkbox: ‘Special user for API (no access to admin.)’
- Go to Configuration > Technical information
- Make sure the following settings are properly configured for this module to work:
  TAB "Global security parameters":
  * Hash algorithm: SHA-1, SHA-256, SHA-512
  * Character encoding: UTF-8

  TAB "Data and origin verification"
  * URL of the merchant page containing the payment form that will call the page:orderstandard.asp
    --> make sure to enter your correct domain here
  * SHA-IN Pass phrase (twice!)
    --> Enter a pass phrase here for security purposes, and remember it for later use
  * IP address of your server (for DirectLink - if appropriate)

  TAB "Transaction feedback"
  * No need to fill in Accepturl, Declineurl, Exceptionurl or Cancelurl. They will be handled automatically
  * Check the checkbox "I want to receive transaction feedback parameters on the redirection URLs.",
    unless you intend to use Direct HTTP server-to-server requests.
  * SHA-OUT Pass phrase
    --> Enter a pass phrase here for security purposes, and remember it for later use
  * Optionally configure the "Direct HTTP server-to-server request" settings,
    if you would like to have Ogone contact your website directly for payment feedback, rather than providing the feedback on redirect.
    (This will avoid problems with users closing their browser after payment was received, but before the redirect back to your site occurred, and other special cases).
    --> Choose a timing setting that suits you best
    --> Set both post-payment URLs to http://<your_website_address>/commerce-ogone/callback
    --> Request method can be POST or GET
    You can do the same for the setting "HTTP request for status changes" if needed.


Installing & configuring the Ogone payment method in Drupal Commerce
---------------------------------------------------------------------
- Enable the module (Go to admin/modules and search in the Commerce (Contrib) fieldset).
- Go to Administration > Store > Configuration > Payment Methods
- Here you can enable either one of the payment methods (Ingenico online gateway: hosted payment methods or Ingenico direct gateway - a direct payment method) or both of them.
- The setting for both of the payment methods should be the same and according to your credentials at Ogone site. This includes the SHA – IN and OUT phrases. 
- The first option is to select either a test account or production site, we can advise you to implement first of all the test account and perform some tests transactions before  
  moving to production! When moving to production do not forget to change the settings here otherwise an issue will appear. 
- The PSID and PSWD are your pspid and password provided by Ogone the USERID is a special api user needed to perform the api calls. If you haven’t done it go to ogone site ->   
  Configuration-> Users, click on the button ‘New user’, add the needed information and select the checkbox ‘Special user for API (no access to admin.)’ 
- The next step is to select the SHA algorithm type, the algorithm type must be the same as the one chosen on ogone site! You can choose between SHA-1, SHA-256, SHA-512. If you  
  didn’t select SHA algorithm on the Ogone backoffice log in on Ogone and go to Technical Information->Global security parameters and choose the algorithm type.
- The SHA-IN and SHA-OUT should be the same as the one on Ogone site, and should be same for both payment methods: Ingenico online gateway  And Ingenico direct gateway, if you haven’t choose a phrase go to ogone site then Technical Information -> Data and origin verification tab for the SHA-IN phrase and   
  Transaction feedback tab for the SHA-OUT phrase.
- Under Transaction capture method you can choose whether your transactions will be automatically authorized and captured(approved) or you can select Manual to capture   
  transactions later.
- For the Ingenico online gateway you can select the checkbox for Preselecting the payment type and choose exactly which of the payment types you would like to implement. 
- Next you can choose a language, which will be used as default language for customers when redirected offsite.- And last but not least merchants can choose whether or not to log issues from the requests. - For the Ingenico direct gateway" method you have additional option to choose whether or not your customers will be using 3d secure authentication or not. By default all  
  transactions will require 3d secure authentication if you want to by pass the authentication select the radio button for ‘Do not perform 3D-Secure checks and always  
  authorise.’
- If you use the manual capture method and if you want to capture all transactions from the day before, or capture all transactions from today you can set up a rule to do that   
  and you should use the action: ‘Capture from a prior authorisation‘ or you can use the example rule in the example module to modify it for your needs, by default this rule  
  captures all transactions from the day before. This rule is triggered on cron run.
- If a merchant wants to use multi currency they should enable the multi currency module and add product prices(through product's settings) for any currencies they want. Restrictions: 
-------------
It is not recommended to modify the module and you are not allowed to save any customer details, only Ogone is PCI compliant and can do so. The client details are directly transmuted to Ogone site, this module is not keeping any card holder information.