<?php

class Kint_Renderer_Rich extends Kint_Renderer
{
    public static $object_renderers = array(
        'blacklist' => 'Kint_Renderer_Rich_Blacklist',
        'callable' => 'Kint_Renderer_Rich_Callable',
        'closure' => 'Kint_Renderer_Rich_Closure',
        'color' => 'Kint_Renderer_Rich_Color',
        'depth_limit' => 'Kint_Renderer_Rich_DepthLimit',
        'nothing' => 'Kint_Renderer_Rich_Nothing',
        'recursion' => 'Kint_Renderer_Rich_Recursion',
        'trace_frame' => 'Kint_Renderer_Rich_TraceFrame',
    );
    public static $tab_renderers = array(
        'binary' => 'Kint_Renderer_Rich_Binary',
        'color' => 'Kint_Renderer_Rich_ColorDetails',
        'docstring' => 'Kint_Renderer_Rich_Docstring',
        'fspath' => 'Kint_Renderer_Rich_FsPath',
        'microtime' => 'Kint_Renderer_Rich_Microtime',
        'source' => 'Kint_Renderer_Rich_Source',
        'table' => 'Kint_Renderer_Rich_Table',
        'timestamp' => 'Kint_Renderer_Rich_Timestamp',
    );
    public static $pre_render_sources = array(
        'script' => array(
            array('Kint_Renderer_Rich', 'renderJs'),
            array('Kint_Renderer_Rich_Microtime', 'renderJs'),
        ),
        'style' => array(
            array('Kint_Renderer_Rich', 'renderCss'),
        ),
        'raw' => array(),
    );

    /**
     * Whether or not to render access paths.
     *
     * Access paths can become incredibly heavy with very deep and wide
     * structures. Given mostly public variables it will typically make
     * up one quarter of the output HTML size.
     *
     * If this is an unacceptably large amount and your browser is groaning
     * under the weight of the access paths - your first order of buisiness
     * should be to get a new browser. Failing that, use this to turn them off.
     *
     * @var bool
     */
    public static $access_paths = true;

    /**
     * The maximum length of a string before it is truncated.
     *
     * Falsey to disable
     *
     * @var int
     */
    public static $strlen_max = 80;

    /**
     * Path to the CSS file to load by default.
     *
     * @var string
     */
    public static $theme = KINT_DIR.'/resources/compiled/original.css';

    private static $been_run = false;

    private $mod_return = false;
    private $mod_clean = false;
    private $callee;
    private $mini_trace;
    private $previous_caller;

    public function __construct(array $params = array())
    {
        parent::__construct($params);

        $params += array(
            'modifiers' => array(),
            'minitrace' => array(),
            'callee' => null,
            'caller' => null,
        );

        $this->mod_return = in_array('@', $params['modifiers']);
        $this->mod_clean = in_array('-', $params['modifiers']);
        $this->callee = $params['callee'];
        $this->mini_trace = $params['minitrace'];
        $this->previous_caller = $params['caller'];
    }

    public function render(Kint_Object $o)
    {
        if ($plugin = $this->getPlugin(self::$object_renderers, $o->hints)) {
            if (strlen($output = $plugin->render($o))) {
                return $output;
            }
        }

        $children = $this->renderChildren($o);
        $header = self::renderHeaderWrapper($o, (bool) strlen($children), self::renderHeader($o));

        return '<dl>'.$header.$children.'</dl>';
    }

    public static function renderHeaderWrapper(Kint_Object $o, $has_children, $contents)
    {
        $open = '<dt';
        $close = '';

        if ($has_children) {
            $open .= ' class="kint-parent';

            if (Kint::$expanded) {
                $open .= ' kint-show';
            }

            $open .= '"';
        }

        $open .= '>';

        if (self::$access_paths && $o->depth > 0 && $ap = $o->getAccessPath()) {
            $open .= '<span class="kint-access-path-trigger" title="Show access path">&rlarr;</span>';
        }

        if ($has_children) {
            $open .= '<span class="kint-popup-trigger" title="Open in new window">&rarr;</span><nav></nav>';
        }

        if (!empty($ap)) {
            $close .= '<div class="access-path">'.Kint_Object_Blob::escape($ap).'</div>';
        }

        return $open.$contents.$close.'</dt>';
    }

    public static function renderHeader(Kint_Object $o)
    {
        $output = '';

        if (($s = $o->getModifiers()) !== null) {
            $output .= '<var>'.$s.'</var> ';
        }

        if (($s = $o->getName()) !== null) {
            $output .= '<dfn>'.Kint_Object_Blob::escape($s).'</dfn> ';

            if ($s = $o->getOperator()) {
                $output .= Kint_Object_Blob::escape($s).' ';
            }
        }

        if (($s = $o->getType()) !== null) {
            $output .= '<var>'.Kint_Object_Blob::escape($s).'</var>';
        }

        if (($s = $o->getSize()) !== null) {
            $output .= '('.$s.') ';
        }

        if (($s = $o->getValueShort()) !== null) {
            $s = preg_replace('/\s+/', ' ', $s);

            if (self::$strlen_max && Kint_Object_Blob::strlen($s) > self::$strlen_max) {
                $s = substr($s, 0, self::$strlen_max).'...';
            }
            $output .= Kint_Object_Blob::escape($s);
        }

        return $output;
    }

    public function renderChildren(Kint_Object $o)
    {
        $contents = array();
        $tabs = array();

        foreach ($o->getRepresentations() as $rep) {
            $result = $this->renderTab($o, $rep);
            if (Kint_Object_Blob::strlen($result)) {
                $contents[] = $result;
                $tabs[] = $rep;
            }
        }

        if (empty($tabs)) {
            return '';
        }

        $output = '<dd>';

        if (count($tabs) === 1 && $tabs[0]->labelIsImplicit()) {
            $output .= reset($contents);
        } else {
            $output .= '<ul class="kint-tabs">';

            foreach ($tabs as $i => $tab) {
                if ($i === 0) {
                    $output .= '<li class="kint-active-tab">';
                } else {
                    $output .= '<li>';
                }
                $output .= Kint_Object_Blob::escape($tab->getLabel()).'</li>';
            }

            $output .= '</ul><ul>';

            foreach ($contents as $tab) {
                $output .= '<li>'.$tab.'</li>';
            }

            $output .= '</ul>';
        }

        return $output.'</dd>';
    }

    private function renderTab(Kint_Object $o, Kint_Object_Representation $rep)
    {
        if ($plugin = $this->getPlugin(self::$tab_renderers, $rep->hints)) {
            if (strlen($output = $plugin->render($rep))) {
                return $output;
            }
        }

        if (is_array($rep->contents)) {
            $output = '';
            foreach ($rep->contents as $obj) {
                $output .= $this->render($obj);
            }

            return $output;
        } elseif (is_string($rep->contents)) {
            $show_contents = false;

            // If it is the value representation of a string and it's whitespace
            // was truncated in the header, always display the full string
            if ($o->type !== 'string' || $o->value !== $rep) {
                $show_contents = true;
            } elseif (preg_match('/(:?[\r\n\t\f\v]| {2})/', $rep->contents)) {
                $show_contents = true;
            } elseif (self::$strlen_max && Kint_Object_Blob::strlen($rep->contents) > self::$strlen_max) {
                $show_contents = true;
            }

            if ($show_contents) {
                return '<pre>'.Kint_Object_Blob::escape($rep->contents).'</pre>';
            }
        } elseif ($rep->contents instanceof Kint_Object) {
            return $this->render($rep->contents);
        }

        return;
    }

    protected function renderJs()
    {
        return file_get_contents(KINT_DIR.'/resources/compiled/rich.js');
    }

    protected function renderCss()
    {
        return file_get_contents(self::$theme);
    }

    public function preRender()
    {
        $output = '';

        if (!self::$been_run || $this->mod_return || $this->mod_clean) {
            foreach (self::$pre_render_sources as $type => $values) {
                $contents = '';
                foreach ($values as $v) {
                    if (is_callable($v)) {
                        $contents .= call_user_func($v, $this);
                    }
                }

                switch ($type) {
                    case 'script':
                        $output .= '<script class="kint-script">'.$contents.'</script>';
                        break;
                    case 'style':
                        $output .= '<style class="kint-style">'.$contents.'</style>';
                        break;
                    default:
                        $output .= $contents;
                }
            }

            if (!$this->mod_return) {
                self::$been_run = true;
            } elseif ($this->mod_clean) {
                self::$been_run = false;
            }
        }

        return $output.'<div class="kint">';
    }

    public function postRender()
    {
        if (!Kint::$display_called_from) {
            return '</div>';
        }

        $output = '<footer>';
        $output .= '<span class="kint-popup-trigger" title="Open in new window">&rarr;</span> ';

        if (isset($this->callee['file'])) {
            if (!empty($this->mini_trace)) {
                $output .= '<nav></nav>';
            }

            $output .= 'Called from '.self::ideLink($this->callee['file'], $this->callee['line']);
        }

        $caller = '';

        if (isset($this->previous_caller['class'])) {
            $caller .= $this->previous_caller['class'];
        }
        if (isset($this->previous_caller['type'])) {
            $caller .= $this->previous_caller['type'];
        }
        if (isset($this->previous_caller['function'])
            && !in_array($this->previous_caller['function'], array('include', 'include_once', 'require', 'require_once'))
        ) {
            $caller .= $this->previous_caller['function'].'()';
        }

        if ($caller) {
            $output .= ' ['.$caller.']';
        }

        if (!empty($this->mini_trace)) {
            $output .= '<ol>';
            foreach ($this->mini_trace as $step) {
                $output .= '<li>'.self::ideLink($step['file'], $step['line']); // closing tag not required
                if (isset($step['function'])
                    && !in_array($step['function'], array('include', 'include_once', 'require', 'require_once'))
                ) {
                    $output .= ' [';
                    if (isset($step['class'])) {
                        $output .= $step['class'];
                    }
                    if (isset($step['type'])) {
                        $output .= $step['type'];
                    }
                    $output .= $step['function'].'()]';
                }
            }
            $output .= '</ol>';
        }

        $output .= '</footer></div>';

        return $output;
    }

    private function getPlugin(array $plugins, array $hints)
    {
        if ($plugins = $this->matchPlugins($plugins, $hints)) {
            $plugin = end($plugins);

            return new $plugin($this);
        }
    }

    private static function ideLink($file, $line)
    {
        $shortenedPath = Kint_Object_Blob::escape(Kint::shortenPath($file));
        if (!Kint::$file_link_format) {
            return $shortenedPath.':'.$line;
        }

        $ideLink = Kint::getIdeLink($file, $line);
        $class = (strpos($ideLink, 'http://') === 0) ? 'class="kint-ide-link" ' : '';

        return "<a {$class}href=\"{$ideLink}\">{$shortenedPath}:{$line}</a>";
    }
}
