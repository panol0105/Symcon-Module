<?php
declare(strict_types=1);

/**
 * TileFont: bettet die Schrift Poppins aus dem lokalen Ordner libs/fonts
 * direkt in die Kachel ein (keine Internetverbindung nötig).
 * Poppins steht unter der SIL Open Font License, siehe libs/fonts/OFL-Poppins.txt.
 */
trait TileFont
{
    protected function tileFontCss(): string
    {
        static $css = null;
        if ($css !== null) {
            return $css;
        }
        $css = '';
        foreach (['Regular' => 400, 'Medium' => 500, 'Bold' => 700] as $file => $weight) {
            $path = __DIR__ . '/fonts/Poppins-' . $file . '.woff';
            if (is_file($path)) {
                $css .= "@font-face{font-family:'Poppins';font-style:normal;font-weight:" . $weight
                    . ";font-display:swap;src:url(data:font/woff;base64," . base64_encode((string)file_get_contents($path)) . ") format('woff');}";
            }
        }
        $css = $css !== '' ? '<style>' . $css . '</style>' : '';
        return $css;
    }
}
