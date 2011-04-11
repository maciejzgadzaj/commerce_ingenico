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
- Go to Configuration > Technical information
- Make sure the following settings are properly configured for this module to work:
  TAB "Global security parameters":
  * Hash algorithm: SHA-1

  TAB "Data and origin verification"
  * URL of the merchant page containing the payment form that will call the page:orderstandard.asp
    --> make sure to enter your correct domain here
  * SHA-IN Pass phrase
    --> Enter a pass phrase here for security purposes, and remember it for later use

  TAB "Transaction feedback"
  * No need to fill in Accepturl, Declineurl, Exceptionurl or Cancelurl. They will be handled automatically
  * Check the checkbox "I want to receive transaction feedback parameters on the redirection URLs."
  * SHA-1-OUT Pass phrase
    --> Enter a pass phrase here for security purposes, and remember it for later use


Installing & configuring the Ogone payment method in Drupal Commerce
---------------------------------------------------------------------
- Enable the module (Go to admin/modules and search in the Commerce (Contrib) fieldset).
- Go to Administration > Store > Configuration > Payment Methods
- Under "Disabled payment method rules", find the Ogone payment method
- Click the 'enable' link
- Once "Ogone" appears in the "Enabled payment method rules", click on it's name to configure
- In the table "Actions", find "Enable payment method: Ogone" and click the link
- Under "Payment settings", you can configure the module:
  * Ogone account: define whether to use the test or production account
  * PSPID: add your Ogone PSPID (the username you use to login as merchant)
  * Currency code: select a currency
  * Language code: let Ogone know in which language the payment screens should be presented
  * SHA-IN Pass phrase: enter the pass phrase you saved while configuring Ogone
  * SHA-1-OUT Pass phrase: enter the pass phrase you saved while configuring Ogone
- You can now process payments with Ogone!

Author
======
Sven Decabooter (http://drupal.org/user/35369) of Pure Sign (http://puresign.be)
The author can be contacted for paid customizations of this module as well as Drupal consulting and development.