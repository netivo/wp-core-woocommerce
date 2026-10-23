# Changelog

## Version 1.1

- Added tax number support for non-Polish billing countries: the field is labelled "VAT / Tax ID" outside Poland ("NIP" in Poland) on the checkout, in the account address form, in addresses and in checkout notices. The checkout label follows the billing country at page load
- Added VAT number format validation for EU countries, GB, CH and NO (an optional country prefix is accepted, including `EL` for Greece); other countries get a generic tax id check
- Added the tax number line to the address formats of the supported countries, keeping each country's own WooCommerce address layout
- The tax number label and validation fall back to the store base country when no billing country is known
- Polish NIP validation now requires digits only
- Fixed a duplicated notice when the tax number is empty on invoice checkout
- Address book tax number validation now runs only for billing addresses

## Version 1.0.5

- Added hiding of online payment gateways when a cash on delivery shipping method is chosen
- Added a setting in WooCommerce > Settings > Shipping > Shipping options for the cash on delivery shipping label fragment (default `pobranie`)

## Version 1.0.4

- Fixed JS for name fields visibility
- Fixed the first name field class

## Version 1.0.3

- Added filter for a person's name and surname hiding during checkout when the invoice is selected
- Added filter for a person's name and surname requirement during checkout when the invoice is selected

## Version 1.0.2

- Fixed JS for company fields in checkout
- Added node for script and styles compiling

## Version 1.0.1

- Dependencies versioning

## Version 1.0

- Created Main WooCommerce abstract class
- On init there will be an added field for NIP and invoice
- Abstract classes for Product Type and Admin Tab