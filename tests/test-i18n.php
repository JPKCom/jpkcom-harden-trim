<?php
/**
 * Guard: the translation catalogue keeps up with the code.
 *
 * Why this exists: the sibling plugins shipped releases whose catalogues had
 * fallen months behind the code, because regenerating was neither automated
 * nor on the release checklist. Every string added in between simply shipped
 * untranslated and nothing said so. This turns that into a red build here
 * before it can happen.
 *
 * It deliberately does NOT reimplement `wp i18n make-pot` - CI has no WP-CLI and
 * no WordPress. It extracts only the shape it can read without ambiguity: a
 * single-line call to one of the gettext wrappers whose first argument is a
 * single-quoted literal and whose second argument is this plugin's text domain.
 * Anything it cannot read with certainty it does not report, because a guard
 * that rejects legitimate code is worse than the gap it closes - the gap needs
 * someone to forget, the false positive needs only someone to commit.
 *
 * Consequence of that choice, stated rather than hidden: a string built by
 * concatenation, or spread over several lines, is invisible here. Those exist in
 * this plugin. The guard catches the ordinary case, which is the one that
 * actually happened.
 *
 * @package JPKComHardenTrim
 */

declare(strict_types=1);

$root   = dirname( __DIR__ );
$slug   = 'jpkcom-harden-trim';
$domain = 'jpkcom-harden-trim';

$pass = 0;
$fail = 0;

/**
 * Assert a condition.
 *
 * @param string $label Check name.
 * @param bool   $ok    Whether the check holds.
 * @param string $why   Explanation printed on failure.
 */
function i18n_chk( string $label, bool $ok, string $why = '' ): void {
	global $pass, $fail;

	if ( $ok ) {
		$pass++;
		echo "  PASS  {$label}\n";
		return;
	}

	$fail++;
	echo "  FAIL  {$label}\n";

	if ( $why !== '' ) {
		echo '        why:  ' . $why . "\n";
	}
}

$pot_path = $root . '/languages/' . $slug . '.pot';

i18n_chk(
	'the .pot exists',
	is_readable( $pot_path ),
	'Expected ' . $pot_path . '. Without it every string ships untranslated and no locale can be started.'
);

if ( ! is_readable( $pot_path ) ) {
	printf( "\n  %d passed, %d failed\n", $pass, $fail );
	exit( 1 );
}

// --- Read the catalogue -----------------------------------------------------

$pot = (string) file_get_contents( $pot_path );

/**
 * Unescape a POT string literal into its plain value.
 *
 * @param string $value Raw literal from the catalogue, without quotes.
 * @return string Plain value.
 */
function i18n_unescape_pot( string $value ): string {
	return strtr(
		$value,
		[
			'\\"'  => '"',
			'\\n'  => "\n",
			'\\t'  => "\t",
			'\\\\' => '\\',
		]
	);
}

$catalogue = [];

// msgid may span several quoted lines; join them before unescaping.
if ( preg_match_all( '/^msgid((?:\s+"(?:[^"\\\\]|\\\\.)*")+)/m', $pot, $matches ) ) {
	foreach ( $matches[1] as $raw ) {
		preg_match_all( '/"((?:[^"\\\\]|\\\\.)*)"/', $raw, $parts );
		$catalogue[ i18n_unescape_pot( implode( '', $parts[1] ) ) ] = true;
	}
}

i18n_chk(
	'the .pot carries entries',
	count( $catalogue ) > 1,
	'Parsed ' . count( $catalogue ) . ' msgids. A catalogue holding only its header means the parse failed, '
	. 'and every check below would pass for the wrong reason.'
);

// --- Read the code ----------------------------------------------------------

$skip = [ '/tests/', '/tools/', '/docs/', '/node_modules/', '/vendor/', '/debug-templates/', '/.git/' ];
$files = [];

$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );

foreach ( $iterator as $file ) {
	$path = str_replace( '\\', '/', $file->getPathname() );

	if ( substr( $path, -4 ) !== '.php' ) {
		continue;
	}

	foreach ( $skip as $fragment ) {
		if ( strpos( $path, $fragment ) !== false ) {
			continue 2;
		}
	}

	$files[] = $path;
}

sort( $files );

// Only the unambiguous shape: one line, single-quoted literal, this domain, and
// nothing glued to either end of the literal.
$wrappers = 'esc_html__|esc_attr__|esc_html_e|esc_attr_e|__|_e|_x|esc_html_x';
$pattern  = '/\b(?:' . $wrappers . ')\(\s*\'((?:[^\'\\\\]|\\\\.)*)\'\s*,\s*\'' . preg_quote( $domain, '/' ) . '\'\s*\)/';

$missing = [];
$found   = 0;

foreach ( $files as $path ) {
	$source = (string) file_get_contents( $path );

	foreach ( explode( "\n", $source ) as $number => $line ) {
		if ( ! preg_match_all( $pattern, $line, $hits, PREG_SET_ORDER ) ) {
			continue;
		}

		foreach ( $hits as $hit ) {
			// PHP single-quoted literals only honour \' and \\.
			$value = strtr( $hit[1], [ "\\'" => "'", '\\\\' => '\\' ] );
			$found++;

			if ( ! isset( $catalogue[ $value ] ) ) {
				$missing[] = str_replace( $root . '/', '', $path ) . ':' . ( $number + 1 ) . '  ' . substr( $value, 0, 90 );
			}
		}
	}
}

i18n_chk(
	'the extractor found translatable strings at all',
	$found > 20,
	'Found ' . $found . '. A regex that matches nothing would make the check below pass on any catalogue, '
	. 'which is the failure mode this assertion exists to prevent.'
);

i18n_chk(
	'every unambiguously translatable string is in the .pot',
	$missing === [],
	count( $missing ) . " string(s) exist in the code and not in the catalogue. Regenerate it:\n"
	. "        ddev wp i18n make-pot <plugin> languages/" . $slug . ".pot --slug=" . $slug
	. " --domain=" . $domain . " --exclude=\"node_modules,tests,tools,docs,.github,debug-templates\"\n"
	. "        then msgmerge each languages/*-*.po against it, msgfmt the .mo, and wp i18n make-php.\n"
	. ( $missing === [] ? '' : "        first few:\n          " . implode( "\n          ", array_slice( $missing, 0, 8 ) ) )
);

// --- The compiled forms must be derived from the .po ------------------------
//
// Since WordPress 6.5 the .l10n.php is the format core loads FIRST. It is not a
// build artefact of the .po - across this plugin family some locales are
// authored directly in it and have no .po at all. Check a file's `x-generator`
// before assuming where it came from.
//
// The two directions are therefore NOT symmetrical:
//
//   .po has a translation the .l10n.php lacks -> FAIL. Core reads the .l10n.php
//     first, so the translation is not being served.
//   .l10n.php has more than the .po           -> legitimate, reported as a NOTE.
//     The danger is not the state but `wp i18n make-php`, which writes the
//     .l10n.php FROM the .po and deletes the difference. That is how the sibling
//     plugin jpkcom-acf-jobs lost 27 German entries in its 1.5.1.
//
// Direction matters: a string translated in the .po and missing from the
// .l10n.php is a build that was not run. A string in the .l10n.php and not in
// the .po is worse - it means the .po is not the source, and the next person to
// regenerate destroys work.

foreach ( glob( $root . '/languages/*-*.po' ) as $po_path ) {
	$locale = preg_replace( '/^.*-([^-]+(?:_[A-Za-z_]+)?)\.po$/', '$1', basename( $po_path ) );
	$php_path = preg_replace( '/\.po$/', '.l10n.php', $po_path );

	if ( ! is_readable( $php_path ) ) {
		i18n_chk(
			$locale . ': the .l10n.php exists beside the .po',
			false,
			'WordPress 6.8+ prefers the .l10n.php. Without it this locale falls back to the .mo, and if '
			. 'that is stale too the strings appear untranslated. Run `wp i18n make-php languages`.'
		);
		continue;
	}

	$po       = (string) file_get_contents( $po_path );
	$compiled = include $php_path;
	$messages = is_array( $compiled ) ? ( $compiled['messages'] ?? $compiled ) : [];

	// Translated msgids in the .po: an entry whose msgstr is a non-empty literal.
	$translated = [];

	foreach ( preg_split( '/\n\s*\n/', $po ) as $entry ) {
		if ( strpos( $entry, '#~' ) !== false ) {
			continue;
		}

		if ( ! preg_match( '/^msgid((?:\s+"(?:[^"\\\\]|\\\\.)*")+)/m', $entry, $id_match ) ) {
			continue;
		}

		// A msgctxt makes the catalogue key "<context>\4<msgid>", which is how
		// WordPress stores and looks it up. Ignoring it reports all 16 of this
		// plugin's block.json entries as missing from a compiled file that has
		// them - a guard failing on a correct catalogue, caught by its own first
		// run against a locale that carries context entries.
		$context = '';

		if ( preg_match( '/^msgctxt((?:\s+"(?:[^"\\\\]|\\\\.)*")+)/m', $entry, $ctx_match ) ) {
			preg_match_all( '/"((?:[^"\\\\]|\\\\.)*)"/', $ctx_match[1], $ctx_parts );
			$context = i18n_unescape_pot( implode( '', $ctx_parts[1] ) ) . chr( 4 );
		}

		// msgstr[0] as well as msgstr: a plural entry carries its translation in
		// the indexed form, and reading only the bare one reports every plural as
		// untranslated in the .po while it is present in the compiled file - a
		// guard failing on a correct catalogue. Caught by the first run against
		// jpkcom-harden-trim, which has one.
		if ( ! preg_match( '/^msgstr(?:\[0\])?((?:\s+"(?:[^"\\\\]|\\\\.)*")+)/m', $entry, $str_match ) ) {
			continue;
		}

		preg_match_all( '/"((?:[^"\\\\]|\\\\.)*)"/', $id_match[1], $id_parts );
		preg_match_all( '/"((?:[^"\\\\]|\\\\.)*)"/', $str_match[1], $str_parts );

		$id  = i18n_unescape_pot( implode( '', $id_parts[1] ) );
		$str = i18n_unescape_pot( implode( '', $str_parts[1] ) );

		if ( $id !== '' && $str !== '' ) {
			$translated[ $context . $id ] = $str;
		}
	}

	$only_in_po  = array_diff_key( $translated, $messages );
	$only_in_php = array_diff_key( $messages, $translated );

	i18n_chk(
		$locale . ': the .l10n.php carries every translation the .po has',
		$only_in_po === [],
		count( $only_in_po ) . ' translated string(s) are in the .po and not in the compiled file, so they '
		. 'are not being served. Run `wp i18n make-php languages` and `msgfmt` the .mo.'
	);

	// Deliberately NOT an assertion. A .l10n.php carrying more than its .po is a
	// legitimate state in this plugin family - some locales are authored directly
	// in that format, which is the one WordPress loads first. Failing on it would
	// be a guard rejecting correct work, and it would push whoever hit it toward
	// `make-php`, the operation that destroys exactly these entries.
	if ( $only_in_php !== [] ) {
		echo '  NOTE  ' . $locale . ': the .l10n.php carries ' . count( $only_in_php )
			. " translation(s) the .po does not.\n";
		echo "        That is allowed - it is the format WordPress loads first. But it means\n";
		echo "        `wp i18n make-php` would DELETE them, because it writes the .l10n.php\n";
		echo "        from the .po. Do not run it for this locale without merging first.\n";
	}
}

// --- The compiled forms must say what the .po says --------------------------
//
// The check above compares keys only. A translation corrected in the .po and
// left stale in the .l10n.php passes it, and core serves the stale text, because
// it reads the .l10n.php first. The .mo is the fallback when the .l10n.php is
// missing or unreadable. So for every translation the .po carries, both compiled
// forms must hold the same string. Entries only the .l10n.php has stay the NOTE
// above; this check does not touch them.

/**
 * Read every translated entry of a .po file.
 *
 * Keys follow WordPress: "<context>\4<msgid>" with a msgctxt, the singular msgid
 * for a plural entry. Plural translations are joined with "\0" in index order,
 * which is how both the .mo and the .l10n.php store them.
 *
 * @param string $po Raw .po content.
 * @return array<string,string> Key => translation; untranslated entries omitted.
 */
function i18n_read_po( string $po ): array {
	$out = [];

	foreach ( preg_split( '/\n\s*\n/', $po ) as $entry ) {
		if ( strpos( $entry, '#~' ) !== false ) {
			continue;
		}

		$fields = [];
		$field  = null;

		foreach ( explode( "\n", $entry ) as $line ) {
			if ( preg_match( '/^(msgctxt|msgid_plural|msgid|msgstr(?:\[\d+\])?)\s+"((?:[^"\\\\]|\\\\.)*)"\s*$/', $line, $m ) ) {
				$field            = $m[1];
				$fields[ $field ] = i18n_unescape_pot( $m[2] );
				continue;
			}

			if ( $field !== null && preg_match( '/^\s*"((?:[^"\\\\]|\\\\.)*)"\s*$/', $line, $m ) ) {
				$fields[ $field ] .= i18n_unescape_pot( $m[1] );
			}
		}

		if ( ! isset( $fields['msgid'] ) || $fields['msgid'] === '' ) {
			continue;
		}

		$key = ( isset( $fields['msgctxt'] ) ? $fields['msgctxt'] . chr( 4 ) : '' ) . $fields['msgid'];

		if ( isset( $fields['msgid_plural'] ) ) {
			$forms = [];

			for ( $i = 0; isset( $fields[ 'msgstr[' . $i . ']' ] ); $i++ ) {
				$forms[] = $fields[ 'msgstr[' . $i . ']' ];
			}

			$value = in_array( '', $forms, true ) ? '' : implode( "\0", $forms );
		} else {
			$value = $fields['msgstr'] ?? '';
		}

		if ( $value !== '' ) {
			$out[ $key ] = $value;
		}
	}

	return $out;
}

/**
 * Read a GNU .mo file.
 *
 * @param string $path Path to the .mo.
 * @return array<string,string>|null Key => translation (keys as in i18n_read_po), null if unreadable.
 */
function i18n_read_mo( string $path ): ?array {
	$data = is_readable( $path ) ? (string) file_get_contents( $path ) : '';

	if ( strlen( $data ) < 28 ) {
		return null;
	}

	$magic = unpack( 'V', substr( $data, 0, 4 ) )[1];

	if ( $magic === 0x950412de ) {
		$fmt = 'V';
	} elseif ( $magic === 0xde120495 ) {
		$fmt = 'N';
	} else {
		return null;
	}

	$head = unpack( $fmt . 'rev/' . $fmt . 'count/' . $fmt . 'orig/' . $fmt . 'trans', substr( $data, 4, 16 ) );
	$out  = [];

	for ( $i = 0; $i < $head['count']; $i++ ) {
		$o = unpack( $fmt . 'len/' . $fmt . 'off', substr( $data, $head['orig'] + 8 * $i, 8 ) );
		$t = unpack( $fmt . 'len/' . $fmt . 'off', substr( $data, $head['trans'] + 8 * $i, 8 ) );

		$original = substr( $data, $o['off'], $o['len'] );

		if ( $original === '' ) {
			continue; // The header entry.
		}

		$out[ explode( "\0", $original )[0] ] = substr( $data, $t['off'], $t['len'] );
	}

	return $out;
}

$po_catalogues = [];

foreach ( glob( $root . '/languages/*-*.po' ) as $po_path ) {
	$locale = preg_replace( '/^.*-([^-]+(?:_[A-Za-z_]+)?)\.po$/', '$1', basename( $po_path ) );
	$po_tr  = i18n_read_po( (string) file_get_contents( $po_path ) );

	$po_catalogues[ $locale ] = $po_tr;

	i18n_chk(
		$locale . ': the .po parser reads translations',
		count( $po_tr ) > 20,
		'Parsed ' . count( $po_tr ) . ' translated entries. A parser that reads nothing would make the value '
		. 'checks below pass on any compiled file.'
	);

	$php_path = preg_replace( '/\.po$/', '.l10n.php', $po_path );
	$mo_path  = preg_replace( '/\.po$/', '.mo', $po_path );

	$compiled = is_readable( $php_path ) ? include $php_path : [];
	$compiled = is_array( $compiled ) ? ( $compiled['messages'] ?? $compiled ) : [];
	$mo       = i18n_read_mo( $mo_path );

	foreach ( [ '.l10n.php' => $compiled, '.mo' => $mo ?? [] ] as $form => $messages ) {
		$stale = [];

		foreach ( $po_tr as $key => $value ) {
			if ( array_key_exists( $key, $messages ) && $messages[ $key ] !== $value ) {
				$stale[] = substr( str_replace( chr( 4 ), ' | ', $key ), 0, 70 ) . '  ->  ' . substr( str_replace( chr( 0 ), ' \\0 ', (string) $messages[ $key ] ), 0, 70 );
			}
		}

		i18n_chk(
			$locale . ': the ' . $form . ' holds the same translation as the .po for every entry',
			$mo !== null && $stale === [],
			( $mo === null ? 'The .mo could not be parsed. ' : '' )
			. count( $stale ) . ' entr(y/ies) differ, so WordPress serves text the .po no longer says. Rebuild '
			. "the .mo with msgfmt -c and the .l10n.php from it (see CLAUDE.md, Localisation).\n          "
			. implode( "\n          ", array_slice( $stale, 0, 8 ) )
		);
	}

	i18n_chk(
		$locale . ': the .mo carries every translation the .po has',
		$mo !== null && array_diff_key( $po_tr, $mo ) === [],
		count( array_diff_key( $po_tr, $mo ?? [] ) ) . ' translated string(s) are missing from the .mo. Run msgfmt -c.'
	);
}

// --- "defaults" and "presets" are two different words ------------------------
//
// "Voreinstellungen" is the German for the three presets (Harmless / Recommended /
// Strict). The values the plugin runs with after installation are the
// "Standardwerte". One word for both made the settings page ambiguous: "the
// presets change nothing visible" read as a statement about the buttons.
// Each of the three English texts below says "defaults" and must not use the
// preset word for it. The only "Voreinstellung" the Harmless summary keeps is
// "jeder Voreinstellung" (every preset), which is the correct use.

$defaults_texts = [
	'Secure, private & lean WordPress: hardening'   => 'the plugin header description',
	'The defaults: nothing that changes how the site' => 'the Harmless preset summary',
	'Hardening, privacy and performance switches. The defaults' => 'the settings page intro',
];

foreach ( $po_catalogues as $locale => $po_tr ) {
	foreach ( $defaults_texts as $prefix => $what ) {
		$hits = [];

		foreach ( $po_tr as $key => $value ) {
			if ( strpos( $key, $prefix ) === 0 ) {
				$hits[] = $value;
			}
		}

		i18n_chk(
			$locale . ': ' . $what . ' is translated',
			count( $hits ) === 1,
			'Expected exactly one translated msgid starting "' . $prefix . '", found ' . count( $hits ) . '.'
		);

		$value = $hits[0] ?? '';
		$rest  = str_replace( 'jeder Voreinstellung', '', $value );

		i18n_chk(
			$locale . ': ' . $what . ' calls the defaults "Standardwerte"',
			strpos( $value, 'Standardwert' ) !== false && strpos( $rest, 'Voreinstellung' ) === false,
			'"defaults" must be "Standardwerte"; "Voreinstellung(en)" means the presets. Got: ' . substr( $value, 0, 120 )
		);
	}
}

// --- One form of address per locale -----------------------------------------
//
// de_DE addresses the reader with "du", de_DE_formal with "Sie". A sentence
// that switches halfway ("... nachdem Sie ihn ausgeschaltet haben - nutze ihn
// nur ...") shipped in the formal catalogue, because the translation source
// marked only part of a sentence as address-dependent. Both directions are
// checked.
//
// What the formal check can see: the du pronouns and possessives (closed set),
// and du imperatives. Imperatives are an open set, so the lexicon is the union
// of (a) every "<verb>en Sie" the formal catalogue itself uses, turned into its
// du form ("Wählen Sie" -> "wähle"), so any verb the plugin addresses formally
// somewhere is caught everywhere; and (b) a seed of imperatives a UI text is
// likely to use, including the irregular ones (a) cannot derive ("lies",
// "gib"). Words that are also German nouns ("Stelle") stay out of the seed.
//
// What the informal check can see: "Sie"/"Ihr..."/"Ihnen" capitalised in the
// middle of a sentence, which in German is only ever the formal address. At the
// start of a sentence the same words also mean "they"/"her", so they are not
// judged there.

$register_seed = [
	'aktiviere', 'aktualisiere', 'beachte', 'benutze', 'deaktiviere', 'entferne', 'füge', 'gehe', 'gib',
	'hilf', 'klicke', 'lade', 'lege', 'lies', 'nimm', 'nutze', 'öffne', 'prüfe', 'schalte', 'setze',
	'sieh', 'speichere', 'trage', 'verwende', 'versuche', 'wähle',
];

if ( isset( $po_catalogues['de_DE_formal'] ) ) {
	$imperatives = array_fill_keys( $register_seed, true );

	foreach ( $po_catalogues['de_DE_formal'] as $value ) {
		if ( preg_match_all( '/(?<!\p{L})(\p{L}+e)n Sie(?!\p{L})/u', $value, $verbs ) ) {
			foreach ( $verbs[1] as $stem ) {
				$imperatives[ mb_strtolower( $stem ) ] = true;
			}
		}
	}

	$informal = '/(?<!\p{L})(du|dich|dir|dein|deine|deinem|deinen|deiner|deines|'
		. implode( '|', array_map( static fn( string $w ): string => preg_quote( $w, '/' ), array_keys( $imperatives ) ) )
		. ')(?!\p{L})/iu';

	$mixed = [];

	foreach ( $po_catalogues['de_DE_formal'] as $key => $value ) {
		if ( preg_match_all( $informal, $value, $hits ) ) {
			$mixed[] = '"' . implode( '", "', array_unique( $hits[1] ) ) . '" in: ' . substr( $value, 0, 110 );
		}
	}

	i18n_chk(
		'de_DE_formal: no translation addresses the reader with du (lexicon of ' . count( $imperatives ) . ' imperatives)',
		$mixed === [],
		count( $mixed ) . " formal translation(s) use a du form:\n          " . implode( "\n          ", $mixed )
	);
}

if ( isset( $po_catalogues['de_DE'] ) ) {
	$mixed = [];

	foreach ( $po_catalogues['de_DE'] as $key => $value ) {
		if ( preg_match_all( '/(?<=[\p{L}\p{N},)"%]\s)(Sie|Ihnen|Ihr|Ihre|Ihrem|Ihren|Ihrer|Ihres)(?!\p{L})/u', $value, $hits ) ) {
			$mixed[] = '"' . implode( '", "', array_unique( $hits[1] ) ) . '" in: ' . substr( $value, 0, 110 );
		}
	}

	i18n_chk(
		'de_DE: no translation addresses the reader with Sie',
		$mixed === [],
		count( $mixed ) . " informal translation(s) use a Sie form:\n          " . implode( "\n          ", $mixed )
	);
}

printf( "\n  %d passed, %d failed\n", $pass, $fail );

exit( $fail > 0 ? 1 : 0 );
