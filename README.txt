CONTENTS OF THIS FILE
---------------------

* Introduction
* Requirements
* Recommended modules
* Installation
* Configuration


INTRODUCTION
------------

This project integrates mobilpay.ro into the Drupal Commerce payment and
checkout systems.


REQUIREMENTS
------------

This module requires the following modules:

* Drupal Commerce (https://www.drupal.org/project/commerce)
* Mobilpay Bundle from https://github.com/birkof/netopia-mobilpay-bundle


INSTALLATION
------------

 * Install as you would normally install a contributed Drupal module. See:
   https://www.drupal.org/docs/8/extending-drupal-8/installing-modules
   for further information.

 * Private files configuration must be done in settings.php file with
    absolute path. eg. ../private

CONFIGURATION
-------------

 * Configure Mobilpay Payment Gateway in Commerce -> Configuration ->
 Payment Gateways:

   - Add new payment gateway:

      - Select Mobilpay Plugin.
      - Fill in the Merchant ID, private and public key provided with your Mobilpay
      registration.
