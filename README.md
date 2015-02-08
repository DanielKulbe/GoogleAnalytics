Google Analytics
================

This extension inserts the Google Analitics tracker code in your pages. Edit the `config.yml` file so
it contains the correct 'webproperty-id'.

### Admin Widget

For the additional widget on the admin pages visit the [Google Developer Console](https://console.developers.google.com) and create a
new application.

In the Services tab, flip the Google Analytics switch (on).

In the API Access tab, click Create an OAuth2.0 Client ID:
  - enter your name, upload a logo, and click Next
  - select the Service account option and press Create client ID
  - download your private key and put it into `app/config/extensions`

Back on the API Access page, you'll see a section called Service account with a Client ID and Email address.
  - copy the email address
  - visit your [GA Admin](https://www.google.com/analytics/web/#management/Accounts/)
    and add this email as a user to your properties (a must; you'll get cryptic errors otherwise.)
