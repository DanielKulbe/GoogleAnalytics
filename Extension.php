<?php
// Google Analytics extension for Bolt

namespace Bolt\Extension\DanielKulbe\GoogleAnalytics;

use Bolt\Extensions\Snippets\Location as SnippetLocation;

class Extension extends \Bolt\BaseExtension
{

    public function getName()
    {
        return "Google Analytics";
    }

    function initialize()
    {
        $this->addSnippet(SnippetLocation::END_OF_HEAD, 'insertAnalytics');

        $additionalhtml = '<script type="text/javascript" src="https://www.google.com/jsapi"></script>';
        $additionalhtml .= '<script>google.load("visualization", "1", {packages:["corechart"]}); </script>';

        if($this->config['widget']) $this->addWidget('dashboard', 'right_first', 'analyticsWidget', $additionalhtml, 3600);
    }

    public function insertAnalytics()
    {

        if (empty($this->config['webproperty'])) {
            $this->config['webproperty'] = "property-not-set";
        }

        if ($this->config['universal']) {

        $html = <<< EOM

    <script>
        (function(i,s,o,g,r,a,m){i['GoogleAnalyticsObject']=r;i[r]=i[r]||function(){
        (i[r].q=i[r].q||[]).push(arguments)},i[r].l=1*new Date();a=s.createElement(o),
        m=s.getElementsByTagName(o)[0];a.async=1;a.src=g;m.parentNode.insertBefore(a,m)
        })(window,document,'script','//www.google-analytics.com/analytics.js','ga');

        ga('create', '%webproperty%', '%domainname%');%displayfeatures%
        ga('send', 'pageview');
    </script>
EOM;

        } else {

        $html = <<< EOM

    <script type="text/javascript">

      var _gaq = _gaq || [];
      _gaq.push(['_setAccount', '%webproperty%']);
      _gaq.push(['_setDomainName', '%domainname%']);
      _gaq.push(['_trackPageview']);

      (function() {
          var ga = document.createElement('script'); ga.type = 'text/javascript'; ga.async = true;
          ga.src = ('https:' == document.location.protocol ? 'https://ssl' : 'http://www') + '.google-analytics.com/ga.js';
          var s = document.getElementsByTagName('script')[0]; s.parentNode.insertBefore(ga, s);
      })();

    </script>
EOM;
    }

        $html = str_replace("%webproperty%", $this->config['webproperty'], $html);
        $html = str_replace("%displayfeatures%", ( $this->config['universal_displayfeatures'] ? " ga('require','displayfeatures');" : '' ), $html);
        $html = str_replace("%domainname%", ( $this->config['universal'] ? $this->config['universal_domainname'] : $_SERVER['HTTP_HOST'] ), $html);

        return new \Twig_Markup($html, 'UTF-8');
    }

    public function analyticsWidget()
    {
        // https://developers.google.com/analytics/devguides/reporting/core/v3/

        if (empty($this->config['profile_id'])) { return "profile_id not set in config.yml."; }
        if (!empty($this->config['filter_referral'])) {
            $filter_referral = 'source !@ "'.$this->config['filter_referral'].'"';
        } else {
            $filter_referral = '';
        }
        if (empty($this->config['number_of_days'])) {
            $this->config['number_of_days'] = 14;
        }

        // API dependencies
        require_once(__DIR__.DIRECTORY_SEPARATOR.'google-api-php-client'.DIRECTORY_SEPARATOR.'autoload.php');

        // create client object and set app name
        $client = new \Google_Client();

        // use Oauth2 Service access
        if (isset($this->config['app_email']) && isset($this->config['app_cert'])) {
            $cert = $this->app['paths']['extensionsconfig'].DIRECTORY_SEPARATOR.$this->config['app_cert'];

            if (!file_exists($cert)) {
                return "OAuth2 app_cert file not found or set, please adjust your config.yml.";
            } elseif (empty($this->config['app_email'])) {
                return "OAuth2 app_mail not set, please adjust your config.yml.";
            }

            $client->setAssertionCredentials( new \Google_Auth_AssertionCredentials(
                $this->config['app_email'],
                array(\Google_Service_Analytics::ANALYTICS_READONLY),
                file_get_contents($cert)
            ) );
        }
        else {
            return "OAuth2 is not properly set up, please adjust your config.yml.";
        }

        $caption = array(
            'start' => date('M d', strtotime('-' . $this->config['number_of_days'] .' day')),
            'end' => date('M d')
        );

        // name of your app
        $client->setApplicationName(empty($this->config['app_name']) ? 'bolt Google Analytics widget' : $this->config['app_name']);

        // create service and get data
        $service = new \Google_Service_Analytics($client);
        $pageviews = $sources = $pages = array();

        // Get the 'pageviews per date'
        $request = $service->data_ga->get(
            'ga:'.$this->config['profile_id'],
            date('Y-m-d', strtotime('-' . $this->config['number_of_days'] .' day')), date('Y-m-d'),
            'ga:pageviews,ga:visitors,ga:uniquePageviews,ga:pageviewsPerSession,ga:exitRate,ga:avgTimeOnPage,ga:bounceRate',
            array(
                'dimensions' => 'ga:date',
                'sort' => 'ga:date',
            )
        );

        foreach ($request->getRows() as $result) {
            $pageviews[] = array( date('M j', strtotime($result[0])), (int)$result[1], (int)$result[2] );
        }

        // aggregate data:
        $aggr = array(
            'pageviews' => (int)$request->totalsForAllResults['ga:pageviews'],
            'visitors' => (int)$request->totalsForAllResults['ga:visitors'],
            'uniquePageviews' => (int)$request->totalsForAllResults['ga:uniquePageviews'],
            'pageviewspervisit' => round((float)$request->totalsForAllResults['ga:pageviewsPerSession'], 1),
            'exitrate' => round((float)$request->totalsForAllResults['ga:exitRate'], 1),
            'timeonpage' => $this->secondMinute(round((float)$request->totalsForAllResults['ga:avgTimeOnPage'], 1)),
            'bouncerate' => round((float)$request->totalsForAllResults['ga:bounceRate'], 1),
        );

        // Get the 'popular sources'
        $request = $service->data_ga->get(
            'ga:'.$this->config['profile_id'],
            date('Y-m-d', strtotime('-' . $this->config['number_of_days'] .' day')), date('Y-m-d'),
            'ga:sessions',
            array(
                'dimensions' => 'ga:source,ga:referralPath',
                'sort' => '-ga:sessions',
                'filters' => 'ga:source!='.$filter_referral.',ga:referralPath!='.$filter_referral,
                'max-results' => '12'
            )
        );

        foreach($request->getRows() as $result) {
            if ($result[1] == "(not set)") {
                $sources[] = array(
                    'link' => false,
                    'host' => $result[0],
                    'visits' => $result[2]
                );
            } else {
                $sources[] = array(
                  'link' => true,
                  'host' => $result[0] . $result[1],
                  'visits' => $result[2]
                );
            }
        }

        // Get the 'popular pages'
        $request = $service->data_ga->get(
            'ga:'.$this->config['profile_id'],
            date('Y-m-d', strtotime('-' . $this->config['number_of_days'] .' day')), date('Y-m-d'),
            'ga:sessions',
            array(
                'dimensions' => 'ga:hostname,ga:referralPath',
                'sort' => '-ga:sessions',
                'filters' => 'ga:source!='.$filter_referral.',ga:referralPath!='.$filter_referral,
                'max-results' => '12'
            )
        );

        foreach($request->getRows() as $result) {
            $pages[] = array(
                'host' => $result[0] . ($result[1] != '(not set)' ? $result[1] : ''),
                'visits' => $result[2]
            );
        }

        $this->app['twig.loader.filesystem']->addPath(__DIR__, 'GoogleAnalytics');
        $html = $this->app['render']->render("@GoogleAnalytics/widget.twig", array(
            'caption' => $caption,
            'aggr' => $aggr,
            'pageviews' => $pageviews,
            'sources' => $sources,
            'pages' => $pages
        ));

        return new \Twig_Markup($html, 'UTF-8');

    }

    private function secondMinute($seconds) {
        return sprintf('%d:%02d', floor($seconds/60), $seconds % 60);
    }

}
