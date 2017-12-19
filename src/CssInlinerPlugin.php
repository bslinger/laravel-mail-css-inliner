<?php

namespace Fedeisas\LaravelMailCssInliner;

use TijsVerkoyen\CssToInlineStyles\CssToInlineStyles;

class CssInlinerPlugin implements \Swift_Events_SendListener
{
    /**
     * @var CssToInlineStyles
     */
    private $converter;

    /**
     * @var string
     */
    protected $css;

	/**
	 * @var array
	 */
	protected $exclusions;

	/**
	 * @var array
	 */
	protected $options;

    /**
     * @param array $options options defined in the configuration file.
     */
    public function __construct(array $options)
    {
        $this->converter = new CssToInlineStyles();
	    $this->options = $options;
	    $this->loadOptions();
    }

    /**
     * @param \Swift_Events_SendEvent $evt
     */
    public function beforeSendPerformed(\Swift_Events_SendEvent $evt)
    {
        $message = $evt->getMessage();

        if ($message->getContentType() === 'text/html'
            || ($message->getContentType() === 'multipart/alternative' && $message->getBody())
            || ($message->getContentType() === 'multipart/mixed' && $message->getBody())
        ) {
            $result = $this->loadCssFilesFromLinks($message->getBody());
            $message->setBody($this->converter->convert($result['message'], $result['css']));
        }

        foreach ($message->getChildren() as $part) {
            if (strpos($part->getContentType(), 'text/html') === 0) {
	            $result = $this->loadCssFilesFromLinks($part->getBody());
                $part->setBody($this->converter->convert($result['message'], $result['css']));
            }
        }
    }

    /**
     * Do nothing
     *
     * @param \Swift_Events_SendEvent $evt
     */
    public function sendPerformed(\Swift_Events_SendEvent $evt)
    {
        // Do Nothing
    }

    /**
     * Load the options
     */
    public function loadOptions()
    {
	    $this->css = '';
	    if (isset($this->options['css-files']) && count($this->options['css-files']) > 0) {
		    $this->css = $this->loadCssFiles($this->options['css-files']);
	    }
	    $this->exclusions = [];
	    if (isset($this->options['exclusions']) && count($this->options['exclusions']) > 0) {
		    $this->exclusions = $this->options['exclusions'];
	    }
    }

	/**
	 * Load the CSS files and join on the shared CSS files already loaded
	 * @param  array $files Files array
	 * @param  boolean $include_shared Whether or not to include the CSS loaded from options
	 *
	 * @return string $css The CSS string
	 */
	public function loadCssFiles($files, $include_shared = true) {
		$css = $include_shared ? $this->css : '';
		foreach ($files as $file) {
			if ($file && !$this->exclusions || !in_array($file, $this->exclusions)) {
				$css .= file_get_contents( $file );
			}
		}
		return $css;
	}

    /**
     * Find CSS stylesheet links and load them
     *
     * Loads the body of the message and passes
     * any link stylesheets to $this->css
     * Removes any link elements
     *
     * @return array $result Array of message and CSS
     */
    public function loadCssFilesFromLinks($message)
    {
	    $dom = new \DOMDocument();
	    // set error level
	    $internalErrors = libxml_use_internal_errors(true);

	    $dom->loadHTML($message);

	    // Restore error level
	    libxml_use_internal_errors($internalErrors);
	    $link_tags = $dom->getElementsByTagName('link');
	    $removing = [];

	    if ($link_tags->length > 0) {
		    $css_files = [];
		    for( $i = 0, $actual_index = 0; $i < $link_tags->length; $i++ ) {
			    if ($link_tags->item($actual_index)->getAttribute('rel') == "stylesheet") {
				    $href = $link_tags->item($actual_index)->getAttribute('href');
				    // Don't remove link elements if their href is in the exclusion list (but keep track of new index value)
				    if ($this->exclusions && in_array($href, $this->exclusions)) {
					    $actual_index++;
					    continue;
				    }

					if(function_exists('public_path')) {
                        $public_path = preg_replace('/\//', '\/', public_path());
                    } else {
                        $public_path = dirname(dirname(__FILE__)) . DIRECTORY_SEPARATOR . 'public';
                    }

                    if(preg_match("/^$public_path/", $href)) {
                        $css_files[] = $href;
                    } else {
                        $css_files[] = public_path($href);
                    }
				    // remove the link node
				    $removing[] = $i;
			    }
			    $actual_index++;
		    }
		    if ( $removing ) {
			    arsort($removing);
			    foreach( $removing as $remove ) {
				    $link_tags->item( $remove )->parentNode->removeChild( $link_tags->item( $remove ) );
			    }
		    }
		    return [
		    	'message' => $dom->saveHTML(),
			    'css' => $this->loadCssFiles($css_files),
		    ];
	    }

	    return [
		    'message' => $message,
		    'css' => null,
	    ];
    }
}
