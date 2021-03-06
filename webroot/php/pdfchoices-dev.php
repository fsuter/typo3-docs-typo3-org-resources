<?php

/* mb, 2013-8-18, 2013-08-19 */

if (0) {
    error_reporting(-1);
    error_reporting(E_ALL ^ E_NOTICE);
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
}

$doDump = 0;
if (1) {
    $devIp = '87.123.243.232';
    if ($_SERVER['REMOTE_ADDR'] === $devIp) {
        $doDump = 1;
    }
    if ($GLOBALS['doDump']) {
    }
// http://docs.typo3.org/php/pdfchoices.php?url=http://docs.typo3.org/typo3cms/drafts/github/marble/DocumentationStarter/a/b/
}


/**
 * This class creates a link based on a requested URL.
 * This link either points to a folder with a PDF file or - if the file does not exist -
 * to a documentation page with instructions on how to set up PDF creation.
 */
class PdfMatcher {

    var $dd = null;     // do debug?
    var $webRootPath = '/home/mbless/public_html';
    /**
     * @var boolean TRUE, if the current page is a glue page, false otherwise
     */
    var $currentProjectIsGluePage = FALSE;
    /**
     * @var string URL to the folder of the PDF file; .htaccess in there will redirect to actual filename
     */
    var $pdfUrl = '';
    /**
     * @var string URL to the page with docs on how to set up rendering.
     * Do not change unless the page is moved accordingly!
     */
    var $pdfDocumentationUrl = 'http://docs.typo3.org/Overview/PdfFiles.html';
    var $knownPathBeginnings = array(
        // longest paths first!
        '/flow/drafts/',
        '/flow/',
        '/neos/drafts/',
        '/neos/',
        '/typo3cms/drafts/github/*/',
        '/typo3cms/drafts/',
        '/typo3cms/extensions/',
        '/typo3cms/',
    );
    /**
     * @var array List of symlinks to resolve
     * @todo: .htaccess already takes care that all requests to "TYPO3/" get rewritten to typo3cms/ directly.
     * This variable and its usages below should be superfluous.
     */
    var $resolveSymlink = array(
        '/TYPO3/drafts/'        => '/typo3cms/drafts/',
        '/TYPO3/extensions/'    => '/typo3cms/extensions/',
        '/TYPO3/'               => '/typo3cms/',
    );
    var $cont               = true;    // continue?
    var $url                = '';      // 'http://docs.typo3.org/typo3cms/TyposcriptReference/en-us/4.7/Setup/Page/Index.html?id=3#abc'
    var $urlPart1           = '';      // 'http://docs.typo3.org'
    var $urlPart2           = '';      // '/typo3cms/'
    var $urlPart3           = '';      // 'TyposcriptReference/4.7/Setup/Page/Index.html?id=3#abc'
    var $filePathToUrlPart2 = '';      // '/typo3cms/'     had been before: '/TYPO3/'

    var $baseFolder         = '';      // 'TyposcriptReference'
    var $localePath         = '';      // 'en-us'
    var $versionPath        = '';      // '4.7'
    var $relativePath       = '';      // 'Setup/Page'
    var $htmlFile           = '';      // 'Index.html'
    var $query              = '';      //  '?id=3'
    var $fragment           = '';      //  '#abc'

    var $parsedUrl;                    // array

    /** @var boolean Information, whether the PDF file exists or not */
    var $pdfExists          = true;
    /** @var string The resulting HTML code of the link */
    var $htmlResult         = '';

    function __construct() {
        // pass
    }

    /** unused */
    function unparse_url($parsed_url) {
        $scheme   = isset($parsed_url['scheme'  ]) ?       $parsed_url['scheme'] . '://' : '';
        $host     = isset($parsed_url['host'    ]) ?       $parsed_url['host']     : '';
        $port     = isset($parsed_url['port'    ]) ? ':' . $parsed_url['port']     : '';
        $user     = isset($parsed_url['user'    ]) ?       $parsed_url['user']     : '';
        $pass     = isset($parsed_url['pass'    ]) ? ':' . $parsed_url['pass']     : '';
        $pass     = ($user || $pass) ? "$pass@" : '';
        $path     = isset($parsed_url['path'    ]) ?       $parsed_url['path']     : '';
        $query    = isset($parsed_url['query'   ]) ? '?' . $parsed_url['query']    : '';
        $fragment = isset($parsed_url['fragment']) ? '#' . $parsed_url['fragment'] : '';
        return "$scheme$user$pass$host$port$path$query$fragment";
    }

    function isValidVersionFolderName($filename) {
        $isValid = false;
        if (!$isValid) { // named versions
            if (in_array( $filename, array('latest', 'stable'))) {
                $isValid = true;
            }
        }
        if (!$isValid) { // numbered versions (like 1.2[.3[.4[.nnn]]])
            $pattern = '~\d+\.\d+(\.\d+)*~is';
            $subpattern = array();
            $result = preg_match($pattern, $filename, $subpattern);
            if ($result and ($subpattern[0] === $filename)) {
                $isValid = true;
            }
        }
        return $isValid;
    }

    function isValidLocaleFolderName($segment) {
        $isValid = false;
        if (!$isValid) { // xx-xx)
            $pattern = '~[a-z][a-z]-[a-z][a-z]~';
            $result = preg_match($pattern, $segment);
            if ($result) {
                $isValid = true;
            }
        }
        return $isValid;
    }

    function parseUrl() {
        $this->parsedUrl = parse_url($this->url);
        $this->urlPart1 = '';
        $this->urlPart1 .= isset($this->parsedUrl['scheme'  ]) ?       $this->parsedUrl['scheme'  ] . '://' : '';
        $this->urlPart1 .= isset($this->parsedUrl['host'    ]) ?       $this->parsedUrl['host'    ]         : '';
        $this->query     = isset($this->parsedUrl['query'   ]) ? '?' . $this->parsedUrl['query'   ]         : '';
        $this->fragment  = isset($this->parsedUrl['fragment']) ? '#' . $this->parsedUrl['fragment']         : '';

        // urlpart1: 'http://docs.typo3.org'
        // urlpart2: '/typo3cms/'
        // urlpart3: 'TyposcriptReference/4.7/Setup/Page/Index.html'

        // Step 1: find urlPart2 and urlPart3
        $found = false;
        foreach ($this->knownPathBeginnings as $this->urlPart2) {
            if ($this->startsWith($this->parsedUrl['path'], $this->urlPart2)) {
                $found = true;
                break;
            }
        }
        if ($found) {
            if (strlen($this->resolveSymlink[$this->urlPart2])) {
                $this->filePathToUrlPart2 = $this->resolveSymlink[$this->urlPart2];
            } else {
                $this->filePathToUrlPart2 = $this->urlPart2;
            }
            $this->urlPart3 = substr($this->parsedUrl['path'], strlen($this->urlPart2));
            $this->urlPart3PathSegments = explode('/', $this->urlPart3);
        } else {
            $this->cont = false;
        }

        /* Check, if we are on a glue page. On these pages, count($this->urlPart3PathSegments) is 0 or 1. */
        if (count($this->urlPart3PathSegments) < 2) {
            $this->currentProjectIsGluePage = TRUE;
            $this->pdfExists = FALSE;
            $this->cont = FALSE;
        }

        # urlPart3PathSegments: array('TyposcriptReference', 'en-us', '4.7', 'Setup', 'Page', 'Index.html');
        if ($this->cont and (count($this->urlPart3PathSegments) < 2)) {
            $this->cont = false;
        }
        $i = 0;
        if ($this->cont) {
            $this->baseFolder = $this->urlPart3PathSegments[$i]; // 'TyposcriptReference'
            $i += 1;
        }
        if ($this->cont) {
            $segment = $this->urlPart3PathSegments[$i];
            if ($this->isValidLocaleFolderName($segment)) {
                $i += 1;
                $this->localePath = $segment;   // 'en-us'
            }
        }
        if ($this->cont) {
            $segment = $this->urlPart3PathSegments[$i];
            if ($this->isValidVersionFolderName($segment)) {
                $this->versionPath = $segment; // '4.7'
                $i += 1;
            }
        }
        if ($this->cont) {
            $this->relativePath = array_slice($this->urlPart3PathSegments, $i); // array('Setup', 'Page','Index.html')
            $this->htmlFile = array_pop($this->relativePath);                   // 'Index.html'
            $this->relativePath = implode('/', $this->relativePath);            // 'Setup/Page'
        }
        if (0 && $GLOBALS['doDump']) {
            $this->dump_and_die($this);
        }
        return;
    }

    function startsWith($haystack, $needle) {
        if (0 && $GLOBALS['doDump']) {
            echo $haystack . '<br>';
            echo $needle . '<br><br>';
        }
        if (strpos($needle, '*') === False) {
            $result = (substr($haystack, 0, strlen($needle)) === $needle);
        } else {
            $long = explode('/', $haystack);
            $short = explode('/', $needle);
            $result = count($long) >= count($short);
            if ($result) {
                $i = 0;
                foreach($short as $part) {
                    if (! ($part === '*' or $part === $long[$i] or $part ==='')) {
                        $result = False;
                        break;
                    }
                    $i = $i + 1;
                }
            }
        }
        return $result;
    }

    /**
     * Creates the HTML output, which should be inserted into the page.
     *
     * @return $result string HTML code of the link
     */
    function generateOutput() {
        $result = '';
        // Only show link, if this is a normal documentation project. Do not show it on the glue pages.
        if (!$this->currentProjectIsGluePage) {
            if ($this->pdfExists) {
                $linkUrl = $this->pdfUrl;
            } else {
                $linkUrl = $this->pdfDocumentationUrl;
            }
            $result = '<li><a href="' . $linkUrl . '">PDF</a></li>';
        }
        if ($this->dd) {
            // highly sophisticated debugging technique :-)
            $result = '<li>' . $this->pdfUrl . '</li>';
            $result = '<li>' . $this->curlResult . '</li>';
            $result = '<li>' . $this->pdf_http_status . '</li>';
            $result = '<li>' . $this->curl_errno . '</li>';
            $result = '';
        }
        return $result;
    }
    function findPdf() {
        if (!$this->cont) {
            return;
        }
        $this->pdfUrl = '';
        $this->pdfUrl .= $this->urlPart1;     // 'http://docs.typo3.org'
        $this->pdfUrl .= $this->urlPart2;     // '/typo3cms/'
        $this->pdfUrl .= $this->baseFolder;   // 'TyposcriptReference'
        $this->pdfUrl .= strlen($this->localePath)  ? '/' . $this->localePath  : '';    // 'en-us'
        $this->pdfUrl .= strlen($this->versionPath) ? '/' . $this->versionPath : '';    // '4.7'
        $this->pdfUrl .= '/_pdf/';
        if (1 && $GLOBALS['doDump']) {
            $this->dump_and_die($this);
        }

        /* Let's check for the existence of a PDF file. We're reading HEAD of the URL and thereby follow redirects.
         * At the shell:
         *      $cmd = 'curl --location --silent --output /dev/null/ --fail --head ' . $pdfUrl
         *      # Exitcode 0: success
         *      # Exitcode 22: No pdf found
         */
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->pdfUrl);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_FAILONERROR, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $this->curlResult = curl_exec($ch);
        $this->curl_errno = curl_errno($ch);
        $this->pdf_http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($this->curl_errno) {
            // Needs CURLOPT_FAILONERROR.
            // On error, most likely ends up with 22 as the exitcode == curl_errno.
            $this->pdfExists = False;
        }
    }

    function dump_and_die($arg) {
        echo '<pre>';
        echo htmlspecialchars(print_r($arg, 1));
        echo '<pre>';
        die();
    }

    /**
     * Main logic of the class.
     *
     * Generates the link to the PDF file in HTML format.
     * @param $url string The complete URL as it was requested by the website visitor
     * @param $doDebug mixed NULL to keep debug mode deactivated, anything else to activate it
     * @param $webRootPath mixed Internal server path to the main folder, which contains the different rendered projects
     * @return string The HTML code with the link
     */
    function processTheUrl($url, $doDebug=null, $webRootPath=null) {
        $this->url = $url;
        if (!is_null($webRootPath)) {
            $this->webRootPath = $webRootPath;
        }
        if (!is_null($doDebug)) {
            $this->dd = $doDebug;
        }
        $this->parseUrl();
        $this->findPdf();
        $this->htmlResult = $this->generateOutput();
        return $this->htmlResult;
    }
}

$pm = new PdfMatcher();
if (1 and 'live') {
    $url = $_GET['url'];
    $htmlResult = $pm->processTheUrl($url);
} else {
    $url = $_GET['url'];
    $url = false;                                   // path: ''
    $url = 'http://docs.typo3.org';                 // path: ''
    $url = 'http://docs.typo3.org/';                // path: '/'
    $url = 'http://docs.typo3.org//';               // path: '//', 3 segments
    $url = 'http://docs.typo3.org/Index.html';      // path: '/Index.html', 2 segments
    $url = 'Index.html';                            // path: 'Index.html', 1 segments
    $url = 'http://docs.typo3.org/typo3cms/TyposcriptReference/#';      // path: 'Index.html', 4 segments
    $url = 'http://docs.typo3.org/typo3cms/TyposcriptReference/4.7/Setup/Page/Index.html?id=3#abc';      // path: 'Index.html', 1 segments
    # $url = false;
    $htmlResult = $pm->processTheUrl($url, 1, '/home/marble/htdocs/LinuxData200/t3doc/versionswitcher/webroot');
}

echo $htmlResult;

?>