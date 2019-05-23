<?php
/**
 * Teampass - a collaborative passwords manager.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 *
 * @author    Nils Laumaillé <nils@teamapss.net>
 * @copyright 2009-2019 Teampass.net
 * @license   https://spdx.org/licenses/GPL-3.0-only.html#licenseText GPL-3.0
 *
 * @version   GIT: <git_id>
 *
 * @see      https://www.teampass.net
 */
if (!isset($_SESSION['CPM']) || $_SESSION['CPM'] != 1) {
    die('Hacking attempt...');
}

// Load config if $SETTINGS not defined
if (!isset($SETTINGS['cpassman_dir']) || empty($SETTINGS['cpassman_dir'])) {
    if (file_exists('../includes/config/tp.config.php')) {
        include_once '../includes/config/tp.config.php';
    } elseif (file_exists('./includes/config/tp.config.php')) {
        include_once './includes/config/tp.config.php';
    } elseif (file_exists('../../includes/config/tp.config.php')) {
        include_once '../../includes/config/tp.config.php';
    } else {
        throw new Exception("Error file '/includes/config/tp.config.php' not exists", 1);
    }
}

header('Content-type: text/html; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

/**
 * Convert language code to string.
 *
 * @param string $string String to get
 *
 * @return string
 */
function langHdl($string)
{
    // Clean the string to convert
    $string = trim($string);

    if (empty($string) === true || isset($_SESSION['teampass']['lang'][$string]) === false) {
        // Manage error
        return 'ERROR in language strings!';
    } else {
        return str_replace(
            array('"', "'"),
            array('&quot;', '&apos;'),
            $_SESSION['teampass']['lang'][$string]
        );
    }
}

//Generate N# of random bits for use as salt
/**
 * Undocumented function.
 *
 * @param int $size Length
 *
 * @return array
 */
function getBits($size)
{
    $str = '';
    $var_x = $size + 10;
    for ($var_i = 0; $var_i < $var_x; ++$var_i) {
        $str .= base_convert(mt_rand(1, 36), 10, 36);
    }

    return substr($str, 0, $size);
}

//generate pbkdf2 compliant hash
function strHashPbkdf2($var_p, $var_s, $var_c, $var_kl, $var_a = 'sha256', $var_st = 0)
{
    $var_kb = $var_st + $var_kl; // Key blocks to compute
    $var_dk = ''; // Derived key

    for ($block = 1; $block <= $var_kb; ++$block) { // Create key
        $var_ib = $var_h = hash_hmac($var_a, $var_s.pack('N', $block), $var_p, true); // Initial hash for this block
        for ($var_i = 1; $var_i < $var_c; ++$var_i) { // Perform block iterations
            $var_ib ^= ($var_h = hash_hmac($var_a, $var_h, $var_p, true)); // XOR each iterate
        }
        $var_dk .= $var_ib; // Append iterated block
    }

    return substr($var_dk, $var_st, $var_kl); // Return derived key of correct length
}

/**
 * stringUtf8Decode().
 *
 * utf8_decode
 */
function stringUtf8Decode($string)
{
    return str_replace(' ', '+', utf8_decode($string));
}

/**
 * encryptOld().
 *
 * crypt a string
 *
 * @param string $text
 */
function encryptOld($text, $personalSalt = '')
{
    if (empty($personalSalt) === false) {
        return trim(
            base64_encode(
                mcrypt_encrypt(
                    MCRYPT_RIJNDAEL_256,
                    $personalSalt,
                    $text,
                    MCRYPT_MODE_ECB,
                    mcrypt_create_iv(
                        mcrypt_get_iv_size(MCRYPT_RIJNDAEL_256, MCRYPT_MODE_ECB),
                        MCRYPT_RAND
                    )
                )
            )
        );
    }

    // If $personalSalt is not empty
    return trim(
        base64_encode(
            mcrypt_encrypt(
                MCRYPT_RIJNDAEL_256,
                SALT,
                $text,
                MCRYPT_MODE_ECB,
                mcrypt_create_iv(
                    mcrypt_get_iv_size(MCRYPT_RIJNDAEL_256, MCRYPT_MODE_ECB),
                    MCRYPT_RAND
                )
            )
        )
    );
}

/**
 * decryptOld().
 *
 * decrypt a crypted string
 */
function decryptOld($text, $personalSalt = '')
{
    if (!empty($personalSalt)) {
        return trim(
            mcrypt_decrypt(
                MCRYPT_RIJNDAEL_256,
                $personalSalt,
                base64_decode($text),
                MCRYPT_MODE_ECB,
                mcrypt_create_iv(
                    mcrypt_get_iv_size(MCRYPT_RIJNDAEL_256, MCRYPT_MODE_ECB),
                    MCRYPT_RAND
                )
            )
        );
    }

    // No personal SK
    return trim(
        mcrypt_decrypt(
            MCRYPT_RIJNDAEL_256,
            SALT,
            base64_decode($text),
            MCRYPT_MODE_ECB,
            mcrypt_create_iv(
                mcrypt_get_iv_size(MCRYPT_RIJNDAEL_256, MCRYPT_MODE_ECB),
                MCRYPT_RAND
            )
        )
    );
}

/**
 * encrypt().
 *
 * crypt a string
 *
 * @param string $decrypted
 */
function encrypt($decrypted, $personalSalt = '')
{
    global $SETTINGS;

    if (!isset($SETTINGS['cpassman_dir']) || empty($SETTINGS['cpassman_dir'])) {
        require_once '../includes/libraries/Encryption/PBKDF2/PasswordHash.php';
    } else {
        require_once $SETTINGS['cpassman_dir'].'/includes/libraries/Encryption/PBKDF2/PasswordHash.php';
    }

    if (!empty($personalSalt)) {
        $staticSalt = $personalSalt;
    } else {
        $staticSalt = SALT;
    }

    //set our salt to a variable
    // Get 64 random bits for the salt for pbkdf2
    $pbkdf2Salt = getBits(64);
    // generate a pbkdf2 key to use for the encryption.
    $key = substr(pbkdf2('sha256', $staticSalt, $pbkdf2Salt, ITCOUNT, 16 + 32, true), 32, 16);
    // Build $init_vect and $ivBase64.  We use a block size of 256 bits (AES compliant)
    // and CTR mode.  (Note: ECB mode is inadequate as IV is not used.)
    $init_vect = mcrypt_create_iv(mcrypt_get_iv_size(MCRYPT_RIJNDAEL_256, 'ctr'), MCRYPT_RAND);

    //base64 trim
    if (strlen($ivBase64 = rtrim(base64_encode($init_vect), '=')) != 43) {
        return false;
    }
    // Encrypt $decrypted
    $encrypted = mcrypt_encrypt(MCRYPT_RIJNDAEL_256, $key, $decrypted, 'ctr', $init_vect);
    // MAC the encrypted text
    $mac = hash_hmac('sha256', $encrypted, $staticSalt);
    // We're done!
    return base64_encode($ivBase64.$encrypted.$mac.$pbkdf2Salt);
}

/**
 * decrypt().
 *
 * decrypt a crypted string
 */
function decrypt($encrypted, $personalSalt = '')
{
    global $SETTINGS;

    if (!isset($SETTINGS['cpassman_dir']) || empty($SETTINGS['cpassman_dir'])) {
        include_once '../includes/libraries/Encryption/PBKDF2/PasswordHash.php';
    } else {
        include_once $SETTINGS['cpassman_dir'].'/includes/libraries/Encryption/PBKDF2/PasswordHash.php';
    }

    if (!empty($personalSalt)) {
        $staticSalt = $personalSalt;
    } else {
        $staticSalt = file_get_contents(SECUREPATH.'/teampass-seckey.txt');
    }
    //base64 decode the entire payload
    $encrypted = base64_decode($encrypted);
    // get the salt
    $pbkdf2Salt = substr($encrypted, -64);
    //remove the salt from the string
    $encrypted = substr($encrypted, 0, -64);
    $key = substr(pbkdf2('sha256', $staticSalt, $pbkdf2Salt, ITCOUNT, 16 + 32, true), 32, 16);
    // Retrieve $init_vect which is the first 22 characters plus ==, base64_decoded.
    $init_vect = base64_decode(substr($encrypted, 0, 43).'==');
    // Remove $init_vect from $encrypted.
    $encrypted = substr($encrypted, 43);
    // Retrieve $mac which is the last 64 characters of $encrypted.
    $mac = substr($encrypted, -64);
    // Remove the last 64 chars from encrypted (remove MAC)
    $encrypted = substr($encrypted, 0, -64);
    //verify the sha256hmac from the encrypted data before even trying to decrypt it
    if (hash_hmac('sha256', $encrypted, $staticSalt) != $mac) {
        return false;
    }
    // Decrypt the data.
    $decrypted = rtrim(mcrypt_decrypt(MCRYPT_RIJNDAEL_256, $key, $encrypted, 'ctr', $init_vect), "\0\4");
    // Yay!
    return $decrypted;
}

/**
 * genHash().
 *
 * Generate a hash for user login
 *
 * @param string $password
 */
function bCrypt($password, $cost)
{
    $salt = sprintf('$2y$%02d$', $cost);
    if (function_exists('openssl_random_pseudo_bytes')) {
        $salt .= bin2hex(openssl_random_pseudo_bytes(11));
    } else {
        $chars = './ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        for ($i = 0; $i < 22; ++$i) {
            $salt .= $chars[mt_rand(0, 63)];
        }
    }

    return crypt($password, $salt);
}

function testHex2Bin($val)
{
    if (!@hex2bin($val)) {
        throw new Exception('ERROR');
    }

    return hex2bin($val);
}

/**
 * Defuse cryption function.
 *
 * @param string $message   what to de/crypt
 * @param string $ascii_key key to use
 * @param string $type      operation to perform
 * @param array  $SETTINGS  Teampass settings
 *
 * @return array
 */
function cryption($message, $ascii_key, $type, $SETTINGS)
{
    // load PhpEncryption library
    if (isset($SETTINGS['cpassman_dir']) === false || empty($SETTINGS['cpassman_dir']) === true) {
        $path = '../includes/libraries/Encryption/Encryption/';
    } else {
        $path = $SETTINGS['cpassman_dir'].'/includes/libraries/Encryption/Encryption/';
    }

    include_once $path.'Crypto.php';
    include_once $path.'Encoding.php';
    include_once $path.'DerivedKeys.php';
    include_once $path.'Key.php';
    include_once $path.'KeyOrPassword.php';
    include_once $path.'File.php';
    include_once $path.'RuntimeTests.php';
    include_once $path.'KeyProtectedByPassword.php';
    include_once $path.'Core.php';

    // init
    $err = '';
    if (empty($ascii_key) === true) {
        $ascii_key = file_get_contents(SECUREPATH.'/teampass-seckey.txt');
    }

    //echo $path.' -- '.$message.' ;; '.$ascii_key.' --- ';
    // convert KEY
    $key = \Defuse\Crypto\Key::loadFromAsciiSafeString($ascii_key);

    try {
        if ($type === 'encrypt') {
            $text = \Defuse\Crypto\Crypto::encrypt($message, $key);
        } elseif ($type === 'decrypt') {
            $text = \Defuse\Crypto\Crypto::decrypt($message, $key);
        }
    } catch (Defuse\Crypto\Exception\WrongKeyOrModifiedCiphertextException $ex) {
        $err = 'an attack! either the wrong key was loaded, or the ciphertext has changed since it was created either corrupted in the database or intentionally modified by someone trying to carry out an attack.';
    } catch (Defuse\Crypto\Exception\BadFormatException $ex) {
        $err = $ex;
    } catch (Defuse\Crypto\Exception\EnvironmentIsBrokenException $ex) {
        $err = $ex;
    } catch (Defuse\Crypto\Exception\CryptoException $ex) {
        $err = $ex;
    } catch (Defuse\Crypto\Exception\IOException $ex) {
        $err = $ex;
    }
    //echo \Defuse\Crypto\Crypto::decrypt($message, $key).' ## ';

    return array(
        'string' => isset($text) ? $text : '',
        'error' => $err,
    );
}

/**
 * Generating a defuse key.
 *
 * @return string
 */
function defuse_generate_key()
{
    // load PhpEncryption library
    if (file_exists('../includes/config/tp.config.php') === true) {
        $path = '../includes/libraries/Encryption/Encryption/';
    } elseif (file_exists('./includes/config/tp.config.php') === true) {
        $path = './includes/libraries/Encryption/Encryption/';
    } else {
        $path = '../includes/libraries/Encryption/Encryption/';
    }

    include_once $path.'Crypto.php';
    include_once $path.'Encoding.php';
    include_once $path.'DerivedKeys.php';
    include_once $path.'Key.php';
    include_once $path.'KeyOrPassword.php';
    include_once $path.'File.php';
    include_once $path.'RuntimeTests.php';
    include_once $path.'KeyProtectedByPassword.php';
    include_once $path.'Core.php';

    $key = \Defuse\Crypto\Key::createNewRandomKey();
    $key = $key->saveToAsciiSafeString();

    return $key;
}

/**
 * Generate a Defuse personal key.
 *
 * @param string $psk psk used
 *
 * @return string
 */
function defuse_generate_personal_key($psk)
{
    // load PhpEncryption library
    if (file_exists('../includes/config/tp.config.php') === true) {
        $path = '../includes/libraries/Encryption/Encryption/';
    } elseif (file_exists('./includes/config/tp.config.php') === true) {
        $path = './includes/libraries/Encryption/Encryption/';
    } else {
        $path = '../includes/libraries/Encryption/Encryption/';
    }

    include_once $path.'Crypto.php';
    include_once $path.'Encoding.php';
    include_once $path.'DerivedKeys.php';
    include_once $path.'Key.php';
    include_once $path.'KeyOrPassword.php';
    include_once $path.'File.php';
    include_once $path.'RuntimeTests.php';
    include_once $path.'KeyProtectedByPassword.php';
    include_once $path.'Core.php';

    $protected_key = \Defuse\Crypto\KeyProtectedByPassword::createRandomPasswordProtectedKey($psk);
    $protected_key_encoded = $protected_key->saveToAsciiSafeString();

    return $protected_key_encoded; // save this in user table
}

/**
 * Validate persoanl key with defuse.
 *
 * @param string $psk                   the user's psk
 * @param string $protected_key_encoded special key
 *
 * @return string
 */
function defuse_validate_personal_key($psk, $protected_key_encoded)
{
    // load PhpEncryption library
    if (file_exists('../includes/config/tp.config.php') === true) {
        $path = '../includes/libraries/Encryption/Encryption/';
    } elseif (file_exists('./includes/config/tp.config.php') === true) {
        $path = './includes/libraries/Encryption/Encryption/';
    } else {
        $path = '../includes/libraries/Encryption/Encryption/';
    }

    include_once $path.'Crypto.php';
    include_once $path.'Encoding.php';
    include_once $path.'DerivedKeys.php';
    include_once $path.'Key.php';
    include_once $path.'KeyOrPassword.php';
    include_once $path.'File.php';
    include_once $path.'RuntimeTests.php';
    include_once $path.'KeyProtectedByPassword.php';
    include_once $path.'Core.php';

    try {
        $protected_key = \Defuse\Crypto\KeyProtectedByPassword::loadFromAsciiSafeString($protected_key_encoded);
        $user_key = $protected_key->unlockKey($psk);
        $user_key_encoded = $user_key->saveToAsciiSafeString();
    } catch (Defuse\Crypto\Exception\EnvironmentIsBrokenException $ex) {
        return 'Error - Major issue as the encryption is broken.';
    } catch (Defuse\Crypto\Exception\WrongKeyOrModifiedCiphertextException $ex) {
        return 'Error - The saltkey is not the correct one.';
    }

    return $user_key_encoded; // store it in session once user has entered his psk
}

/**
 * Decrypt a defuse string if encrypted.
 *
 * @param string $value Encrypted string
 *
 * @return string Decrypted string
 */
function defuseReturnDecrypted($value, $SETTINGS)
{
    if (substr($value, 0, 3) === 'def') {
        $value = cryption($value, '', 'decrypt', $SETTINGS)['string'];
    }

    return $value;
}

/**
 * Trims a string depending on a specific string.
 *
 * @param string|array $chaine  what to trim
 * @param string       $element trim on what
 *
 * @return string
 */
function trimElement($chaine, $element)
{
    if (!empty($chaine)) {
        if (is_array($chaine) === true) {
            $chaine = implode(';', $chaine);
        }
        $chaine = trim($chaine);
        if (substr($chaine, 0, 1) === $element) {
            $chaine = substr($chaine, 1);
        }
        if (substr($chaine, strlen($chaine) - 1, 1) === $element) {
            $chaine = substr($chaine, 0, strlen($chaine) - 1);
        }
    }

    return $chaine;
}

/**
 * Permits to suppress all "special" characters from string.
 *
 * @param string $string  what to clean
 * @param bool   $special use of special chars?
 *
 * @return string
 */
function cleanString($string, $special = false)
{
    // Create temporary table for special characters escape
    $tabSpecialChar = array();
    for ($i = 0; $i <= 31; ++$i) {
        $tabSpecialChar[] = chr($i);
    }
    array_push($tabSpecialChar, '<br />');
    if ((int) $special === 1) {
        $tabSpecialChar = array_merge($tabSpecialChar, array('</li>', '<ul>', '<ol>'));
    }

    return str_replace($tabSpecialChar, "\n", $string);
}

/**
 * Erro manager for DB.
 *
 * @param array $params output from query
 */
function db_error_handler($params)
{
    echo 'Error: '.$params['error']."<br>\n";
    echo 'Query: '.$params['query']."<br>\n";
    throw new Exception('Error - Query', 1);
}

/**
 * [identifyUserRights description].
 *
 * @param string $groupesVisiblesUser  [description]
 * @param string $groupesInterditsUser [description]
 * @param string $isAdmin              [description]
 * @param string $idFonctions          [description]
 *
 * @return string [description]
 */
function identifyUserRights(
    $groupesVisiblesUser,
    $groupesInterditsUser,
    $isAdmin,
    $idFonctions,
    $SETTINGS
) {
    //load ClassLoader
    include_once $SETTINGS['cpassman_dir'].'/sources/SplClassLoader.php';

    //Connect to DB
    include_once $SETTINGS['cpassman_dir'].'/includes/libraries/Database/Meekrodb/db.class.php';
    if (defined('DB_PASSWD_CLEAR') === false) {
        define('DB_PASSWD_CLEAR', defuseReturnDecrypted(DB_PASSWD, $SETTINGS));
    }
    DB::$host = DB_HOST;
    DB::$user = DB_USER;
    DB::$password = DB_PASSWD_CLEAR;
    DB::$dbName = DB_NAME;
    DB::$port = DB_PORT;
    DB::$encoding = DB_ENCODING;

    //Build tree
    $tree = new SplClassLoader('Tree\NestedTree', $SETTINGS['cpassman_dir'].'/includes/libraries');
    $tree->register();
    $tree = new Tree\NestedTree\NestedTree(prefixTable('nested_tree'), 'id', 'parent_id', 'title');

    // Check if user is ADMINISTRATOR
    if ((int) $isAdmin === 1) {
        identAdmin(
            $idFonctions,
            $SETTINGS,
            $tree
        );
    } else {
        identUser(
            $groupesVisiblesUser,
            $groupesInterditsUser,
            $idFonctions,
            $SETTINGS,
            $tree
        );
    }

    // update user's timestamp
    DB::update(
        prefixTable('users'),
        array(
            'timestamp' => time(),
        ),
        'id=%i',
        $_SESSION['user_id']
    );
}

/**
 * Identify administrator.
 *
 * @param string $idFonctions Roles of user
 * @param array  $SETTINGS    Teampass settings
 * @param array  $tree        Tree of folders
 */
function identAdmin($idFonctions, $SETTINGS, $tree)
{
    $groupesVisibles = array();
    $_SESSION['personal_folders'] = array();
    $_SESSION['groupes_visibles'] = array();
    $_SESSION['no_access_folders'] = array();
    $_SESSION['personal_visible_groups'] = array();
    $_SESSION['read_only_folders'] = array();
    $_SESSION['list_restricted_folders_for_items'] = array();
    $_SESSION['list_folders_editable_by_role'] = array();
    $_SESSION['list_folders_limited'] = array();
    $_SESSION['no_access_folders'] = array();

    // Get list of Folders
    $rows = DB::query('SELECT id FROM '.prefixTable('nested_tree').' WHERE personal_folder = %i', 0);
    foreach ($rows as $record) {
        array_push($groupesVisibles, $record['id']);
    }
    $_SESSION['groupes_visibles'] = $groupesVisibles;
    $_SESSION['all_non_personal_folders'] = $groupesVisibles;
    // Exclude all PF
    $_SESSION['forbiden_pfs'] = array();
    $where = new WhereClause('and'); // create a WHERE statement of pieces joined by ANDs
    $where->add('personal_folder=%i', 1);
    if (isset($SETTINGS['enable_pf_feature']) === true
        && (int) $SETTINGS['enable_pf_feature'] === 1
    ) {
        $where->add('title=%s', $_SESSION['user_id']);
        $where->negateLast();
    }
    // Get ID of personal folder
    $persfld = DB::queryfirstrow(
        'SELECT id FROM '.prefixTable('nested_tree').' WHERE title = %s',
        $_SESSION['user_id']
    );
    if (empty($persfld['id']) === false) {
        if (in_array($persfld['id'], $_SESSION['groupes_visibles']) === false) {
            array_push($_SESSION['groupes_visibles'], $persfld['id']);
            array_push($_SESSION['personal_visible_groups'], $persfld['id']);
            // get all descendants
            $tree = new Tree\NestedTree\NestedTree(prefixTable('nested_tree'), 'id', 'parent_id', 'title');
            $tree->rebuild();
            $tst = $tree->getDescendants($persfld['id']);
            foreach ($tst as $t) {
                array_push($_SESSION['groupes_visibles'], $t->id);
                array_push($_SESSION['personal_visible_groups'], $t->id);
            }
        }
    }

    // get complete list of ROLES
    $tmp = explode(';', $idFonctions);
    $rows = DB::query(
        'SELECT * FROM '.prefixTable('roles_title').'
        ORDER BY title ASC'
    );
    foreach ($rows as $record) {
        if (!empty($record['id']) && !in_array($record['id'], $tmp)) {
            array_push($tmp, $record['id']);
        }
    }
    $_SESSION['fonction_id'] = implode(';', $tmp);

    $_SESSION['is_admin'] = 1;
    // Check if admin has created Folders and Roles
    DB::query('SELECT * FROM '.prefixTable('nested_tree').'');
    $_SESSION['nb_folders'] = DB::count();
    DB::query('SELECT * FROM '.prefixTable('roles_title'));
    $_SESSION['nb_roles'] = DB::count();
}

/**
 * Permits to convert an element to array.
 *
 * @param string|array $element Any value to be returned as array
 *
 * @return array
 */
function convertToArray($element)
{
    if (is_string($element) === true) {
        if (empty($element) === true) {
            return array();
        } else {
            return explode(
                ';',
                trimElement($element, ';')
            );
        }
    } else {
        return $element;
    }
}

/**
 * Defines the rights the user has.
 *
 * @param string|array $allowedFolders  Allowed folders
 * @param string|array $noAccessFolders Not allowed folders
 * @param string|array $userRoles       Roles of user
 * @param array        $SETTINGS        Teampass settings
 * @param array        $tree            Tree of folders
 */
function identUser(
    $allowedFolders,
    $noAccessFolders,
    $userRoles,
    $SETTINGS,
    $tree
) {
    // init
    $_SESSION['groupes_visibles'] = array();
    $_SESSION['personal_folders'] = array();
    $_SESSION['no_access_folders'] = array();
    $_SESSION['personal_visible_groups'] = array();
    $_SESSION['read_only_folders'] = array();
    $_SESSION['fonction_id'] = $userRoles;
    $_SESSION['is_admin'] = '0';
    $personalFolders = array();
    $readOnlyFolders = array();
    $noAccessPersonalFolders = array();
    $restrictedFoldersForItems = array();
    $foldersEditableByRole = array();
    $foldersLimited = array();
    $foldersLimitedFull = array();
    $allowedFoldersByRoles = array();

    // Ensure consistency in array format
    $noAccessFolders = convertToArray($noAccessFolders);
    $userRoles = convertToArray($userRoles);
    $allowedFolders = convertToArray($allowedFolders);

    // Get list of folders depending on Roles
    $rows = DB::query(
        'SELECT *
        FROM '.prefixTable('roles_values').'
        WHERE role_id IN %li AND type IN %ls',
        $userRoles,
        array('W', 'ND', 'NE', 'NDNE', 'R')
    );
    foreach ($rows as $record) {
        if ($record['type'] === 'R') {
            array_push($readOnlyFolders, $record['folder_id']);
        } elseif (in_array($record['folder_id'], $allowedFolders) === false) {
            array_push($allowedFoldersByRoles, $record['folder_id']);
        }
    }
    $allowedFoldersByRoles = array_unique($allowedFoldersByRoles);
    $readOnlyFolders = array_unique($readOnlyFolders);

    // Clean arrays
    foreach ($readOnlyFolders as $key => $val) {
        if (in_array($readOnlyFolders[$key], $allowedFoldersByRoles) === true) {
            unset($readOnlyFolders[$key]);
        }
    }

    // Does this user is allowed to see other items
    $inc = 0;
    $rows = DB::query(
        'SELECT id, id_tree FROM '.prefixTable('items').'
        WHERE restricted_to LIKE %ss AND inactif = %s',
        $_SESSION['user_id'].';',
        '0'
    );
    foreach ($rows as $record) {
        // Exclude restriction on item if folder is fully accessible
        if (in_array($record['id_tree'], $allowedFolders) === false) {
            $restrictedFoldersForItems[$record['id_tree']][$inc] = $record['id'];
            ++$inc;
        }
    }

    // Check for the users roles if some specific rights exist on items
    $rows = DB::query(
        'SELECT i.id_tree, r.item_id
        FROM '.prefixTable('items').' as i
        INNER JOIN '.prefixTable('restriction_to_roles').' as r ON (r.item_id=i.id)
        WHERE r.role_id IN %li
        ORDER BY i.id_tree ASC',
        $userRoles
    );
    $inc = 0;
    foreach ($rows as $record) {
        if (isset($record['id_tree'])) {
            $foldersLimited[$record['id_tree']][$inc] = $record['item_id'];
            array_push($foldersLimitedFull, $record['item_id']);
            ++$inc;
        }
    }

    // Get list of Personal Folders
    if (isset($SETTINGS['enable_pf_feature']) === true && (int) $SETTINGS['enable_pf_feature'] === 1
        && isset($_SESSION['personal_folder']) === true && (int) $_SESSION['personal_folder'] === 1
    ) {
        $persoFld = DB::queryfirstrow(
            'SELECT id
            FROM '.prefixTable('nested_tree').'
            WHERE title = %s AND personal_folder = %i',
            $_SESSION['user_id'],
            1
        );
        if (empty($persoFld['id']) === false) {
            if (in_array($persoFld['id'], $allowedFolders) === false) {
                array_push($personalFolders, $persoFld['id']);
                array_push($allowedFolders, $persoFld['id']);
                //array_push($_SESSION['personal_visible_groups'], $persoFld['id']);
                // get all descendants
                $ids = $tree->getChildren($persoFld['id'], false);
                foreach ($ids as $ident) {
                    if ((int) $ident->personal_folder === 1) {
                        array_push($allowedFolders, $ident->id);
                        //array_push($_SESSION['personal_visible_groups'], $ident->id);
                        array_push($personalFolders, $ident->id);
                    }
                }
            }
        }
    }

    // Exclude all other PF
    $where = new WhereClause('and');
    $where->add('personal_folder=%i', 1);
    if (isset($SETTINGS['enable_pf_feature']) === true && (int) $SETTINGS['enable_pf_feature'] === 1
        && isset($_SESSION['personal_folder']) === true && (int) $_SESSION['personal_folder'] === 1
    ) {
        $where->add('title=%s', $_SESSION['user_id']);
        $where->negateLast();
    }
    $persoFlds = DB::query(
        'SELECT id
        FROM '.prefixTable('nested_tree').'
        WHERE %l',
        $where
    );
    foreach ($persoFlds as $persoFldId) {
        array_push($noAccessPersonalFolders, $persoFldId['id']);
    }

    // All folders visibles
    $allowedFolders = array_merge(
        $foldersLimitedFull,
        $allowedFoldersByRoles,
        $restrictedFoldersForItems,
        $readOnlyFolders
    );

    // Exclude from allowed folders all the specific user forbidden folders
    if (count($noAccessFolders) > 0) {
        $allowedFolders = array_diff($allowedFolders, $noAccessFolders);
    }

    // Return data
    $_SESSION['all_non_personal_folders'] = $allowedFolders;
    $_SESSION['groupes_visibles'] = array_merge($allowedFolders, $personalFolders);
    $_SESSION['read_only_folders'] = $readOnlyFolders;
    $_SESSION['no_access_folders'] = $noAccessFolders;
    $_SESSION['personal_folders'] = $personalFolders;
    $_SESSION['list_folders_limited'] = $foldersLimited;
    $_SESSION['list_folders_editable_by_role'] = $allowedFoldersByRoles;
    $_SESSION['list_restricted_folders_for_items'] = $restrictedFoldersForItems;
    $_SESSION['forbiden_pfs'] = $noAccessPersonalFolders;
    $_SESSION['all_folders_including_no_access'] = array_merge(
        $allowedFolders,
        $personalFolders,
        $noAccessFolders,
        $readOnlyFolders
    );

    // Folders and Roles numbers
    DB::queryfirstrow('SELECT id FROM '.prefixTable('nested_tree').'');
    $_SESSION['nb_folders'] = DB::count();
    DB::queryfirstrow('SELECT id FROM '.prefixTable('roles_title'));
    $_SESSION['nb_roles'] = DB::count();

    // check if change proposals on User's items
    if (isset($SETTINGS['enable_suggestion']) === true && (int) $SETTINGS['enable_suggestion'] === 1) {
        DB::query(
            'SELECT *
            FROM '.prefixTable('items_change').' AS c
            LEFT JOIN '.prefixTable('log_items').' AS i ON (c.item_id = i.id_item)
            WHERE i.action = %s AND i.id_user = %i',
            'at_creation',
            $_SESSION['user_id']
        );
        $_SESSION['nb_item_change_proposals'] = DB::count();
    } else {
        $_SESSION['nb_item_change_proposals'] = 0;
    }

    return true;
}

/**
 * Update the CACHE table.
 *
 * @param string $action   What to do
 * @param array  $SETTINGS Teampass settings
 * @param string $ident    Ident format
 */
function updateCacheTable($action, $SETTINGS, $ident = null)
{
    if ($action === 'reload') {
        // Rebuild full cache table
        cacheTableRefresh($SETTINGS);
    } elseif ($action === 'update_value' && is_null($ident) === false) {
        // UPDATE an item
        cacheTableUpdate($SETTINGS, $ident);
    } elseif ($action === 'add_value' && is_null($ident) === false) {
        // ADD an item
        cacheTableAdd($SETTINGS, $ident);
    } elseif ($action === 'delete_value' && is_null($ident) === false) {
        // DELETE an item
        DB::delete(prefixTable('cache'), 'id = %i', $ident);
    }
}

/**
 * Cache table - refresh.
 *
 * @param array $SETTINGS Teampass settings
 */
function cacheTableRefresh($SETTINGS)
{
    include_once $SETTINGS['cpassman_dir'].'/sources/SplClassLoader.php';

    //Connect to DB
    include_once $SETTINGS['cpassman_dir'].'/includes/libraries/Database/Meekrodb/db.class.php';
    if (defined('DB_PASSWD_CLEAR') === false) {
        define('DB_PASSWD_CLEAR', defuseReturnDecrypted(DB_PASSWD, $SETTINGS));
    }
    DB::$host = DB_HOST;
    DB::$user = DB_USER;
    DB::$password = DB_PASSWD_CLEAR;
    DB::$dbName = DB_NAME;
    DB::$port = DB_PORT;
    DB::$encoding = DB_ENCODING;

    //Load Tree
    $tree = new SplClassLoader('Tree\NestedTree', '../includes/libraries');
    $tree->register();
    $tree = new Tree\NestedTree\NestedTree(prefixTable('nested_tree'), 'id', 'parent_id', 'title');

    // truncate table
    DB::query('TRUNCATE TABLE '.prefixTable('cache'));

    // reload date
    $rows = DB::query(
        'SELECT *
        FROM '.prefixTable('items').' as i
        INNER JOIN '.prefixTable('log_items').' as l ON (l.id_item = i.id)
        AND l.action = %s
        AND i.inactif = %i',
        'at_creation',
        0
    );
    foreach ($rows as $record) {
        if (empty($record['id_tree']) === false) {
            // Get all TAGS
            $tags = '';
            $itemTags = DB::query(
                'SELECT tag
                FROM '.prefixTable('tags').'
                WHERE item_id = %i AND tag != ""',
                $record['id']
            );
            foreach ($itemTags as $itemTag) {
                $tags .= $itemTag['tag'].' ';
            }

            // Get renewal period
            $resNT = DB::queryfirstrow(
                'SELECT renewal_period
                FROM '.prefixTable('nested_tree').'
                WHERE id = %i',
                $record['id_tree']
            );

            // form id_tree to full foldername
            $folder = array();
            $arbo = $tree->getPath($record['id_tree'], true);
            foreach ($arbo as $elem) {
                // Check if title is the ID of a user
                if (is_numeric($elem->title) === true) {
                    // Is this a User id?
                    $user = DB::queryfirstrow(
                        'SELECT id, login
                        FROM '.prefixTable('users').'
                        WHERE id = %i',
                        $elem->title
                    );
                    if (count($user) > 0) {
                        $elem->title = $user['login'];
                    }
                }
                // Build path
                array_push($folder, stripslashes($elem->title));
            }
            // store data
            DB::insert(
                prefixTable('cache'),
                array(
                    'id' => $record['id'],
                    'label' => $record['label'],
                    'description' => isset($record['description']) ? $record['description'] : '',
                    'url' => (isset($record['url']) && !empty($record['url'])) ? $record['url'] : '0',
                    'tags' => $tags,
                    'id_tree' => $record['id_tree'],
                    'perso' => $record['perso'],
                    'restricted_to' => (isset($record['restricted_to']) && !empty($record['restricted_to'])) ? $record['restricted_to'] : '0',
                    'login' => isset($record['login']) ? $record['login'] : '',
                    'folder' => implode(' > ', $folder),
                    'author' => $record['id_user'],
                    'renewal_period' => isset($resNT['renewal_period']) ? $resNT['renewal_period'] : '0',
                    'timestamp' => $record['date'],
                )
            );
        }
    }
}

/**
 * Cache table - update existing value.
 *
 * @param array  $SETTINGS Teampass settings
 * @param string $ident    Ident format
 */
function cacheTableUpdate($SETTINGS, $ident = null)
{
    include_once $SETTINGS['cpassman_dir'].'/sources/SplClassLoader.php';

    //Connect to DB
    include_once $SETTINGS['cpassman_dir'].'/includes/libraries/Database/Meekrodb/db.class.php';
    if (defined('DB_PASSWD_CLEAR') === false) {
        define('DB_PASSWD_CLEAR', defuseReturnDecrypted(DB_PASSWD, $SETTINGS));
    }
    DB::$host = DB_HOST;
    DB::$user = DB_USER;
    DB::$password = DB_PASSWD_CLEAR;
    DB::$dbName = DB_NAME;
    DB::$port = DB_PORT;
    DB::$encoding = DB_ENCODING;

    //Load Tree
    $tree = new SplClassLoader('Tree\NestedTree', '../includes/libraries');
    $tree->register();
    $tree = new Tree\NestedTree\NestedTree(prefixTable('nested_tree'), 'id', 'parent_id', 'title');

    // get new value from db
    $data = DB::queryfirstrow(
        'SELECT label, description, id_tree, perso, restricted_to, login, url
        FROM '.prefixTable('items').'
        WHERE id=%i',
        $ident
    );
    // Get all TAGS
    $tags = '';
    $itemTags = DB::query(
        'SELECT tag
        FROM '.prefixTable('tags').'
        WHERE item_id = %i AND tag != ""',
        $ident
    );
    foreach ($itemTags as $itemTag) {
        $tags .= $itemTag['tag'].' ';
    }
    // form id_tree to full foldername
    $folder = array();
    $arbo = $tree->getPath($data['id_tree'], true);
    foreach ($arbo as $elem) {
        // Check if title is the ID of a user
        if (is_numeric($elem->title) === true) {
            // Is this a User id?
            $user = DB::queryfirstrow(
                'SELECT id, login
                FROM '.prefixTable('users').'
                WHERE id = %i',
                $elem->title
            );
            if (count($user) > 0) {
                $elem->title = $user['login'];
            }
        }
        // Build path
        array_push($folder, stripslashes($elem->title));
    }
    // finaly update
    DB::update(
        prefixTable('cache'),
        array(
            'label' => $data['label'],
            'description' => $data['description'],
            'tags' => $tags,
            'url' => (isset($data['url']) && !empty($data['url'])) ? $data['url'] : '0',
            'id_tree' => $data['id_tree'],
            'perso' => $data['perso'],
            'restricted_to' => (isset($data['restricted_to']) && !empty($data['restricted_to'])) ? $data['restricted_to'] : '0',
            'login' => isset($data['login']) ? $data['login'] : '',
            'folder' => implode(' » ', $folder),
            'author' => $_SESSION['user_id'],
            ),
        'id = %i',
        $ident
    );
}

/**
 * Cache table - add new value.
 *
 * @param array  $SETTINGS Teampass settings
 * @param string $ident    Ident format
 */
function cacheTableAdd($SETTINGS, $ident = null)
{
    include_once $SETTINGS['cpassman_dir'].'/sources/SplClassLoader.php';

    //Connect to DB
    include_once $SETTINGS['cpassman_dir'].'/includes/libraries/Database/Meekrodb/db.class.php';
    if (defined('DB_PASSWD_CLEAR') === false) {
        define('DB_PASSWD_CLEAR', defuseReturnDecrypted(DB_PASSWD, $SETTINGS));
    }
    DB::$host = DB_HOST;
    DB::$user = DB_USER;
    DB::$password = DB_PASSWD_CLEAR;
    DB::$dbName = DB_NAME;
    DB::$port = DB_PORT;
    DB::$encoding = DB_ENCODING;

    //Load Tree
    $tree = new SplClassLoader('Tree\NestedTree', '../includes/libraries');
    $tree->register();
    $tree = new Tree\NestedTree\NestedTree(prefixTable('nested_tree'), 'id', 'parent_id', 'title');

    // get new value from db
    $data = DB::queryFirstRow(
        'SELECT i.label, i.description, i.id_tree as id_tree, i.perso, i.restricted_to, i.id, i.login, i.url, l.date
        FROM '.prefixTable('items').' as i
        INNER JOIN '.prefixTable('log_items').' as l ON (l.id_item = i.id)
        WHERE i.id = %i
        AND l.action = %s',
        $ident,
        'at_creation'
    );
    // Get all TAGS
    $tags = '';
    $itemTags = DB::query(
        'SELECT tag
        FROM '.prefixTable('tags').'
        WHERE item_id = %i AND tag != ""',
        $ident
    );
    foreach ($itemTags as $itemTag) {
        $tags .= $itemTag['tag'].' ';
    }
    // form id_tree to full foldername
    $folder = array();
    $arbo = $tree->getPath($data['id_tree'], true);
    foreach ($arbo as $elem) {
        // Check if title is the ID of a user
        if (is_numeric($elem->title) === true) {
            // Is this a User id?
            $user = DB::queryfirstrow(
                'SELECT id, login
                FROM '.prefixTable('users').'
                WHERE id = %i',
                $elem->title
            );
            if (count($user) > 0) {
                $elem->title = $user['login'];
            }
        }
        // Build path
        array_push($folder, stripslashes($elem->title));
    }
    // finaly update
    DB::insert(
        prefixTable('cache'),
        array(
            'id' => $data['id'],
            'label' => $data['label'],
            'description' => $data['description'],
            'tags' => (isset($tags) && empty($tags) === false) ? $tags : 'None',
            'url' => (isset($data['url']) && !empty($data['url'])) ? $data['url'] : '0',
            'id_tree' => $data['id_tree'],
            'perso' => (isset($data['perso']) && empty($data['perso']) === false && $data['perso'] !== 'None') ? $data['perso'] : '0',
            'restricted_to' => (isset($data['restricted_to']) && empty($data['restricted_to']) === false) ? $data['restricted_to'] : '0',
            'login' => isset($data['login']) ? $data['login'] : '',
            'folder' => implode(' » ', $folder),
            'author' => $_SESSION['user_id'],
            'timestamp' => $data['date'],
        )
    );
}

/**
 * Do statistics.
 *
 * @param array $SETTINGS Teampass settings
 *
 * @return array
 */
function getStatisticsData($SETTINGS)
{
    DB::query(
        'SELECT id FROM '.prefixTable('nested_tree').' WHERE personal_folder = %i',
        0
    );
    $counter_folders = DB::count();

    DB::query(
        'SELECT id FROM '.prefixTable('nested_tree').' WHERE personal_folder = %i',
        1
    );
    $counter_folders_perso = DB::count();

    DB::query(
        'SELECT id FROM '.prefixTable('items').' WHERE perso = %i',
        0
    );
    $counter_items = DB::count();

    DB::query(
        'SELECT id FROM '.prefixTable('items').' WHERE perso = %i',
        1
    );
    $counter_items_perso = DB::count();

    DB::query(
        'SELECT id FROM '.prefixTable('users').''
    );
    $counter_users = DB::count();

    DB::query(
        'SELECT id FROM '.prefixTable('users').' WHERE admin = %i',
        1
    );
    $admins = DB::count();

    DB::query(
        'SELECT id FROM '.prefixTable('users').' WHERE gestionnaire = %i',
        1
    );
    $managers = DB::count();

    DB::query(
        'SELECT id FROM '.prefixTable('users').' WHERE read_only = %i',
        1
    );
    $readOnly = DB::count();

    // list the languages
    $usedLang = [];
    $tp_languages = DB::query(
        'SELECT name FROM '.prefixTable('languages')
    );
    foreach ($tp_languages as $tp_language) {
        DB::query(
            'SELECT * FROM '.prefixTable('users').' WHERE user_language = %s',
            $tp_language['name']
        );
        $usedLang[$tp_language['name']] = round((DB::count() * 100 / $counter_users), 0);
    }

    // get list of ips
    $usedIp = [];
    $tp_ips = DB::query(
        'SELECT user_ip FROM '.prefixTable('users')
    );
    foreach ($tp_ips as $ip) {
        if (array_key_exists($ip['user_ip'], $usedIp)) {
            $usedIp[$ip['user_ip']] = $usedIp[$ip['user_ip']] + 1;
        } elseif (!empty($ip['user_ip']) && $ip['user_ip'] !== 'none') {
            $usedIp[$ip['user_ip']] = 1;
        }
    }

    return array(
        'error' => '',
        'stat_phpversion' => phpversion(),
        'stat_folders' => $counter_folders,
        'stat_folders_shared' => intval($counter_folders) - intval($counter_folders_perso),
        'stat_items' => $counter_items,
        'stat_items_shared' => intval($counter_items) - intval($counter_items_perso),
        'stat_users' => $counter_users,
        'stat_admins' => $admins,
        'stat_managers' => $managers,
        'stat_ro' => $readOnly,
        'stat_kb' => $SETTINGS['enable_kb'],
        'stat_pf' => $SETTINGS['enable_pf_feature'],
        'stat_fav' => $SETTINGS['enable_favourites'],
        'stat_teampassversion' => $SETTINGS['cpassman_version'],
        'stat_ldap' => $SETTINGS['ldap_mode'],
        'stat_agses' => $SETTINGS['agses_authentication_enabled'],
        'stat_duo' => $SETTINGS['duo'],
        'stat_suggestion' => $SETTINGS['enable_suggestion'],
        'stat_api' => $SETTINGS['api'],
        'stat_customfields' => $SETTINGS['item_extra_fields'],
        'stat_syslog' => $SETTINGS['syslog_enable'],
        'stat_2fa' => $SETTINGS['google_authentication'],
        'stat_stricthttps' => $SETTINGS['enable_sts'],
        'stat_mysqlversion' => DB::serverVersion(),
        'stat_languages' => $usedLang,
        'stat_country' => $usedIp,
    );
}

/**
 * Permits to send an email.
 *
 * @param string $subject     email subject
 * @param string $textMail    email message
 * @param string $email       email
 * @param array  $SETTINGS    settings
 * @param string $textMailAlt email message alt
 * @param bool   $silent      no errors
 *
 * @return string some json info
 */
function sendEmail(
    $subject,
    $textMail,
    $email,
    $SETTINGS,
    $textMailAlt = null,
    $silent = true
) {
    // CAse where email not defined
    if ($email === 'none') {
        return '"error":"" , "message":"'.langHdl('forgot_my_pw_email_sent').'"';
    }

    // Load settings
    include_once $SETTINGS['cpassman_dir'].'/includes/config/settings.php';

    // Load superglobal
    include_once $SETTINGS['cpassman_dir'].'/includes/libraries/protect/SuperGlobal/SuperGlobal.php';
    $superGlobal = new protect\SuperGlobal\SuperGlobal();

    // Get user language
    $session_user_language = $superGlobal->get('user_language', 'SESSION');
    $user_language = isset($session_user_language) ? $session_user_language : 'english';
    include_once $SETTINGS['cpassman_dir'].'/includes/language/'.$user_language.'.php';

    // Load library
    include_once $SETTINGS['cpassman_dir'].'/sources/SplClassLoader.php';

    // load PHPMailer
    $mail = new SplClassLoader('Email\PHPMailer', '../includes/libraries');
    $mail->register();
    $mail = new Email\PHPMailer\PHPMailer(true);
    try {
        // send to user
        $mail->setLanguage('en', $SETTINGS['cpassman_dir'].'/includes/libraries/Email/PHPMailer/language/');
        $mail->SMTPDebug = 0; //value 1 can be used to debug - 4 for debuging connections
        $mail->Port = $SETTINGS['email_port']; //COULD BE USED
        $mail->CharSet = 'utf-8';
        $mail->SMTPSecure = ($SETTINGS['email_security'] === 'tls'
        || $SETTINGS['email_security'] === 'ssl') ? $SETTINGS['email_security'] : '';
        $mail->SMTPAutoTLS = ($SETTINGS['email_security'] === 'tls'
            || $SETTINGS['email_security'] === 'ssl') ? true : false;
        $mail->SMTPOptions = array(
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ),
        );
        $mail->isSmtp(); // send via SMTP
        $mail->Host = $SETTINGS['email_smtp_server']; // SMTP servers
        $mail->SMTPAuth = (int) $SETTINGS['email_smtp_auth'] === 1 ? true : false; // turn on SMTP authentication
        $mail->Username = $SETTINGS['email_auth_username']; // SMTP username
        $mail->Password = $SETTINGS['email_auth_pwd']; // SMTP password
        $mail->From = $SETTINGS['email_from'];
        $mail->FromName = $SETTINGS['email_from_name'];

        // Prepare for each person
        foreach (array_filter(explode(',', $email)) as $dest) {
            $mail->addAddress($dest);
        }

        // Prepare HTML
        $text_html = emailBody($textMail);

        $mail->WordWrap = 80; // set word wrap
        $mail->isHtml(true); // send as HTML
        $mail->Subject = $subject;
        $mail->Body = $text_html;
        $mail->AltBody = (is_null($textMailAlt) === false) ? $textMailAlt : '';

        // send email
        if ($mail->send()) {
            if ($silent === false) {
                return json_encode(
                    array(
                        'error' => false,
                        'message' => langHdl('forgot_my_pw_email_sent'),
                    )
                );
            }
        } elseif ($silent === false) {
            return json_encode(
                array(
                    'error' => true,
                    'message' => str_replace(array("\n", "\t", "\r"), '', $mail->ErrorInfo),
                )
            );
        }
    } catch (Exception $e) {
        if ($silent === false) {
            return json_encode(
                array(
                    'error' => true,
                    'message' => str_replace(array("\n", "\t", "\r"), '', $mail->ErrorInfo),
                )
            );
        }
    }
}

/**
 * Returns the email body.
 *
 * @param string $textMail Text for the email
 *
 * @return string
 */
function emailBody($textMail)
{
    return '<!DOCTYPE html PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN" "http://www.=
    w3.org/TR/html4/loose.dtd"><html>
    <head><title>Email Template</title>
    <style type="text/css">
    body { background-color: #f0f0f0; padding: 10px 0; margin:0 0 10px =0; }
    </style></head>
    <body style="-ms-text-size-adjust: none; size-adjust: none; margin: 0; padding: 10px 0; background-color: #f0f0f0;" bgcolor="#f0f0f0" leftmargin="0" topmargin="0" marginwidth="0" marginheight="0">
    <table border="0" width="100%" height="100%" cellpadding="0" cellspacing="0" bgcolor="#f0f0f0" style="border-spacing: 0;">
    <tr><td style="border-collapse: collapse;"><br>
        <table border="0" width="100%" cellpadding="0" cellspacing="0" bgcolor="#17357c" style="border-spacing: 0; margin-bottom: 25px;">
        <tr><td style="border-collapse: collapse; padding: 11px 20px;">
            <div style="max-width:150px; max-height:34px; color:#f0f0f0; font-weight:bold;">Teampass</div>
        </td></tr></table></td>
    </tr>
    <tr><td align="center" valign="top" bgcolor="#f0f0f0" style="border-collapse: collapse; background-color: #f0f0f0;">
        <table width="600" cellpadding="0" cellspacing="0" border="0" class="container" bgcolor="#ffffff" style="border-spacing: 0; border-bottom: 1px solid #e0e0e0; box-shadow: 0 0 3px #ddd; color: #434343; font-family: Helvetica, Verdana, sans-serif;">
        <tr><td class="container-padding" bgcolor="#ffffff" style="border-collapse: collapse; border-left: 1px solid #e0e0e0; background-color: #ffffff; padding-left: 30px; padding-right: 30px;">
        <br><div style="float:right;">'.
    $textMail.
    '<br><br></td></tr></table>
    </td></tr></table>
    <br></body></html>';
}

/**
 * Generate a Key.
 *
 * @return string
 */
function generateKey()
{
    return substr(md5(rand().rand()), 0, 15);
}

/**
 * Convert date to timestamp.
 *
 * @param string $date     The date
 * @param array  $SETTINGS Teampass settings
 *
 * @return string
 */
function dateToStamp($date, $SETTINGS)
{
    $date = date_parse_from_format($SETTINGS['date_format'], $date);
    if ((int) $date['warning_count'] === 0 && (int) $date['error_count'] === 0) {
        return mktime(23, 59, 59, $date['month'], $date['day'], $date['year']);
    } else {
        return '';
    }
}

/**
 * Is this a date.
 *
 * @param string $date Date
 *
 * @return bool
 */
function isDate($date)
{
    return strtotime($date) !== false;
}

/**
 * isUTF8().
 *
 * @return int is the string in UTF8 format
 */
function isUTF8($string)
{
    if (is_array($string) === true) {
        $string = $string['string'];
    }

    return preg_match(
        '%^(?:
        [\x09\x0A\x0D\x20-\x7E] # ASCII
        | [\xC2-\xDF][\x80-\xBF] # non-overlong 2-byte
        | \xE0[\xA0-\xBF][\x80-\xBF] # excluding overlongs
        | [\xE1-\xEC\xEE\xEF][\x80-\xBF]{2} # straight 3-byte
        | \xED[\x80-\x9F][\x80-\xBF] # excluding surrogates
        | \xF0[\x90-\xBF][\x80-\xBF]{2} # planes 1-3
        | [\xF1-\xF3][\x80-\xBF]{3} # planes 4-15
        | \xF4[\x80-\x8F][\x80-\xBF]{2} # plane 16
        )*$%xs',
        $string
    );
}

/**
 * Prepare an array to UTF8 format before JSON_encode.
 *
 * @param array $array Array of values
 *
 * @return array
 */
function utf8Converter($array)
{
    array_walk_recursive(
        $array,
        function (&$item, $key) {
            if (mb_detect_encoding($item, 'utf-8', true) === false) {
                $item = utf8_encode($item);
            }
        }
    );

    return $array;
}

/**
 * Permits to prepare data to be exchanged.
 *
 * @param array|string $data Text
 * @param string       $type Parameter
 * @param string       $key  Optional key
 *
 * @return string|array
 */
function prepareExchangedData($data, $type, $key = null)
{
    if (isset($SETTINGS['cpassman_dir']) === false || empty($SETTINGS['cpassman_dir'])) {
        if (file_exists('../includes/config/tp.config.php')) {
            include '../includes/config/tp.config.php';
        } elseif (file_exists('./includes/config/tp.config.php')) {
            include './includes/config/tp.config.php';
        } elseif (file_exists('../../includes/config/tp.config.php')) {
            include '../../includes/config/tp.config.php';
        } else {
            throw new Exception("Error file '/includes/config/tp.config.php' not exists", 1);
        }
    }
    
    //load ClassLoader
    include_once $SETTINGS['cpassman_dir'].'/sources/SplClassLoader.php';
    //Load AES
    $aes = new SplClassLoader('Encryption\Crypt', $SETTINGS['cpassman_dir'].'/includes/libraries');
    $aes->register();

    if ($key !== null) {
        $_SESSION['key'] = $key;
    }

    if ($type === 'encode' && is_array($data) === true) {
        // Ensure UTF8 format
        $data = utf8Converter($data);
        // Now encode
        if (isset($SETTINGS['encryptClientServer'])
            && $SETTINGS['encryptClientServer'] === '0'
        ) {
            return json_encode(
                $data,
                JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
            );
        } else {
            return Encryption\Crypt\aesctr::encrypt(
                json_encode(
                    $data,
                    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
                ),
                $_SESSION['key'],
                256
            );
        }
    } elseif ($type === 'decode' && is_array($data) === false) {
        if (isset($SETTINGS['encryptClientServer'])
            && $SETTINGS['encryptClientServer'] === '0'
        ) {
            return json_decode(
                $data,
                true
            );
        } else {
            return json_decode(
                Encryption\Crypt\aesctr::decrypt(
                    $data,
                    $_SESSION['key'],
                    256
                ),
                true
            );
        }
    }
}

/**
 * Create a thumbnail.
 *
 * @param string $src           Source
 * @param string $dest          Destination
 * @param float  $desired_width Size of width
 */
function makeThumbnail($src, $dest, $desired_width)
{
    /* read the source image */
    $source_image = imagecreatefrompng($src);
    $width = imagesx($source_image);
    $height = imagesy($source_image);

    /* find the "desired height" of this thumbnail, relative to the desired width  */
    $desired_height = floor($height * ($desired_width / $width));

    /* create a new, "virtual" image */
    $virtual_image = imagecreatetruecolor($desired_width, $desired_height);

    /* copy source image at a resized size */
    imagecopyresampled($virtual_image, $source_image, 0, 0, 0, 0, $desired_width, $desired_height, $width, $height);

    /* create the physical thumbnail image to its destination */
    imagejpeg($virtual_image, $dest);
}

/**
 * Check table prefix in SQL query.
 *
 * @param string $table Table name
 *
 * @return string
 */
function prefixTable($table)
{
    $safeTable = htmlspecialchars(DB_PREFIX.$table);
    if (!empty($safeTable)) {
        // sanitize string
        return $safeTable;
    } else {
        // stop error no table
        return 'table_not_exists';
    }
}

/*
 * Creates a KEY using PasswordLib
 */
function GenerateCryptKey($size = null, $secure = false, $numerals = false, $capitalize = false, $symbols = false)
{
    include_once 'SplClassLoader.php';

    if ($secure === true) {
        $numerals = true;
        $capitalize = true;
        $symbols = true;
    }

    // Load libraries
    if (file_exists('../includes/config/tp.config.php')) {
        $generator = new SplClassLoader('PasswordGenerator\Generator', '../includes/libraries');
    } elseif (file_exists('./includes/config/tp.config.php')) {
        $generator = new SplClassLoader('PasswordGenerator\Generator', './includes/libraries');
    } else {
        throw new Exception('Error file not exists', 1);
    }

    $generator->register();
    $generator = new PasswordGenerator\Generator\ComputerPasswordGenerator();

    // Can we use PHP7 random_int function?
    /*if (version_compare(phpversion(), '7.0', '>=')) {
        include_once $SETTINGS['cpassman_dir'].'/includes/libraries/PasswordGenerator/RandomGenerator/Php7RandomGenerator.php';
        $generator->setRandomGenerator(new PasswordGenerator\RandomGenerator\Php7RandomGenerator());
    }*/

    // init
    if (empty($size) === false && is_null($size) === false) {
        $generator->setLength(intval($size));
    }
    if (empty($numerals) === false) {
        $generator->setNumbers($numerals);
    }
    if (empty($capitalize) === false) {
        $generator->setUppercase($capitalize);
    }
    if (empty($symbols) === false) {
        $generator->setSymbols($symbols);
    }

    // generate and send back
    return $generator->generatePassword();
}

/*
* Send sysLOG message
* @param string $message
* @param string $host
*/
function send_syslog($message, $host, $port, $component = 'teampass')
{
    $sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    $syslog_message = '<123>'.date('M d H:i:s ').$component.': '.$message;
    socket_sendto($sock, $syslog_message, strlen($syslog_message), 0, $host, $port);
    socket_close($sock);
}

/**
 * logEvents().
 *
 * permits to log events into DB
 *
 * @param string $type
 * @param string $label
 * @param string $field_1
 */
function logEvents($type, $label, $who, $login = null, $field_1 = null)
{
    global $server, $user, $pass, $database, $port, $encoding;
    global $SETTINGS;

    if (empty($who)) {
        $who = getClientIpServer();
    }

    // include librairies & connect to DB
    require_once $SETTINGS['cpassman_dir'].'/includes/libraries/Database/Meekrodb/db.class.php';
    if (defined('DB_PASSWD_CLEAR') === false) {
        define('DB_PASSWD_CLEAR', defuseReturnDecrypted(DB_PASSWD, $SETTINGS));
    }
    DB::$host = DB_HOST;
    DB::$user = DB_USER;
    DB::$password = DB_PASSWD_CLEAR;
    DB::$dbName = DB_NAME;
    DB::$port = DB_PORT;
    DB::$encoding = DB_ENCODING;
    //$link = mysqli_connect(DB_HOST, DB_USER, DB_PASSWD_CLEAR, DB_NAME, DB_PORT);
    //$link->set_charset(DB_ENCODING);

    DB::insert(
        prefixTable('log_system'),
        array(
            'type' => $type,
            'date' => time(),
            'label' => $label,
            'qui' => $who,
            'field_1' => $field_1 === null ? '' : $field_1,
        )
    );

    // If SYSLOG
    if (isset($SETTINGS['syslog_enable']) === true && (int) $SETTINGS['syslog_enable'] === 1) {
        if ($type === 'user_mngt') {
            send_syslog(
                'action='.str_replace('at_', '', $label).' attribute=user user='.$who.' userid="'.$login.'" change="'.$field_1.'" ',
                $SETTINGS['syslog_host'],
                $SETTINGS['syslog_port'],
                'teampass'
            );
        } else {
            send_syslog(
                'action='.$type.' attribute='.$label.' user='.$who.' userid="'.$login.'" ',
                $SETTINGS['syslog_host'],
                $SETTINGS['syslog_port'],
                'teampass'
            );
        }
    }
}

/**
 * Log events.
 *
 * @param array  $SETTINGS        Teampass settings
 * @param int    $item_id         Item id
 * @param string $item_label      Item label
 * @param int    $id_user         User id
 * @param string $action          Code for reason
 * @param string $login           User login
 * @param string $raison          Code for reason
 * @param string $encryption_type Encryption on
 */
function logItems(
    $SETTINGS,
    $item_id,
    $item_label,
    $id_user,
    $action,
    $login = null,
    $raison = null,
    $encryption_type = null
) {
    // include librairies & connect to DB
    include_once $SETTINGS['cpassman_dir'].'/includes/libraries/Database/Meekrodb/db.class.php';
    if (defined('DB_PASSWD_CLEAR') === false) {
        define('DB_PASSWD_CLEAR', defuseReturnDecrypted(DB_PASSWD, $SETTINGS));
    }
    DB::$host = DB_HOST;
    DB::$user = DB_USER;
    DB::$password = DB_PASSWD_CLEAR;
    DB::$dbName = DB_NAME;
    DB::$port = DB_PORT;
    DB::$encoding = DB_ENCODING;

    // Insert log in DB
    DB::insert(
        prefixTable('log_items'),
        array(
            'id_item' => $item_id,
            'date' => time(),
            'id_user' => $id_user,
            'action' => $action,
            'raison' => $raison,
            'raison_iv' => '',
            'encryption_type' => is_null($encryption_type) === true ? TP_ENCRYPTION_NAME : $encryption_type,
        )
    );
    // Timestamp the last change
    if ($action === 'at_creation' || $action === 'at_modifiation' || $action === 'at_delete' || $action === 'at_import') {
        DB::update(
            prefixTable('misc'),
            array(
                'valeur' => time(),
                ),
            'type = %s AND intitule = %s',
            'timestamp',
            'last_item_change'
        );
    }

    // SYSLOG
    if (isset($SETTINGS['syslog_enable']) === true && $SETTINGS['syslog_enable'] === '1') {
        // Extract reason
        $attribute = explode(' : ', $raison);

        // Get item info if not known
        if (empty($item_label) === true) {
            $dataItem = DB::queryfirstrow(
                'SELECT id, id_tree, label
                FROM '.prefixTable('items').'
                WHERE id = %i',
                $item_id
            );

            $item_label = $dataItem['label'];
        }

        send_syslog(
            'action='.str_replace('at_', '', $action).' attribute='.str_replace('at_', '', $attribute[0]).' itemno='.$item_id.' user='.addslashes($login).' itemname="'.addslashes($item_label).'"',
            $SETTINGS['syslog_host'],
            $SETTINGS['syslog_port'],
            'teampass'
        );
    }

    // send notification if enabled
    notifyOnChange($item_id, $action, $SETTINGS);
}

/**
 * If enabled, then notify admin/manager.
 *
 * @param int    $item_id  Item id
 * @param string $action   Action to do
 * @param array  $SETTINGS Teampass settings
 */
function notifyOnChange($item_id, $action, $SETTINGS)
{
    if (isset($SETTINGS['enable_email_notification_on_item_shown']) === true
        && (int) $SETTINGS['enable_email_notification_on_item_shown'] === 1
        && $action === 'at_shown'
    ) {
        // Get info about item
        $dataItem = DB::queryfirstrow(
            'SELECT id, id_tree, label
            FROM '.prefixTable('items').'
            WHERE id = %i',
            $item_id
        );
        $item_label = $dataItem['label'];

        // send back infos
        DB::insert(
            prefixTable('emails'),
            array(
                'timestamp' => time(),
                'subject' => langHdl('email_on_open_notification_subject'),
                'body' => str_replace(
                    array('#tp_user#', '#tp_item#', '#tp_link#'),
                    array(
                        addslashes($_SESSION['name'].' '.$_SESSION['lastname']),
                        addslashes($item_label),
                        $SETTINGS['cpassman_url'].'/index.php?page=items&group='.$dataItem['id_tree'].'&id='.$item_id,
                    ),
                    langHdl('email_on_open_notification_mail')
                ),
                'receivers' => $_SESSION['listNotificationEmails'],
                'status' => '',
            )
        );
    }
}

/**
 * Prepare notification email to subscribers.
 *
 * @param int    $item_id  Item id
 * @param string $label    Item label
 * @param array  $changes  List of changes
 * @param array  $SETTINGS Teampass settings
 */
function notifyChangesToSubscribers($item_id, $label, $changes, $SETTINGS)
{
    // send email to user that what to be notified
    $notification = DB::queryOneColumn(
        'email',
        'SELECT *
        FROM '.prefixTable('notification').' AS n
        INNER JOIN '.prefixTable('users').' AS u ON (n.user_id = u.id)
        WHERE n.item_id = %i AND n.user_id != %i',
        $item_id,
        $_SESSION['user_id']
    );

    if (DB::count() > 0) {
        // Prepare path
        $path = geItemReadablePath($item_id, '', $SETTINGS);

        // Get list of changes
        $htmlChanges = '<ul>';
        foreach ($changes as $change) {
            $htmlChanges .= '<li>'.$change.'</li>';
        }
        $htmlChanges .= '</ul>';

        // send email
        DB::insert(
            prefixTable('emails'),
            array(
                'timestamp' => time(),
                'subject' => langHdl('email_subject_item_updated'),
                'body' => str_replace(
                    array('#item_label#', '#folder_name#', '#item_id#', '#url#', '#name#', '#lastname#', '#changes#'),
                    array($label, $path, $item_id, $SETTINGS['cpassman_url'], $_SESSION['name'], $_SESSION['lastname'], $htmlChanges),
                    langHdl('email_body_item_updated')
                ),
                'receivers' => implode(',', $notification),
                'status' => '',
            )
        );
    }
}

/**
 * Returns the Item + path.
 *
 * @param int    $id_tree
 * @param string $label
 * @param array  $SETTINGS
 *
 * @return string
 */
function geItemReadablePath($id_tree, $label, $SETTINGS)
{
    // Class loader
    require_once $SETTINGS['cpassman_dir'].'/sources/SplClassLoader.php';

    //Load Tree
    $tree = new SplClassLoader('Tree\NestedTree', '../includes/libraries');
    $tree->register();
    $tree = new Tree\NestedTree\NestedTree(prefixTable('nested_tree'), 'id', 'parent_id', 'title');

    $arbo = $tree->getPath($id_tree, true);
    $path = '';
    foreach ($arbo as $elem) {
        if (empty($path) === true) {
            $path = htmlspecialchars(stripslashes(htmlspecialchars_decode($elem->title, ENT_QUOTES)), ENT_QUOTES).' ';
        } else {
            $path .= '&#8594; '.htmlspecialchars(stripslashes(htmlspecialchars_decode($elem->title, ENT_QUOTES)), ENT_QUOTES);
        }
    }

    // Build text to show user
    if (empty($label) === false) {
        return empty($path) === true ? addslashes($label) : addslashes($label).' ('.$path.')';
    } else {
        return empty($path) === true ? '' : $path;
    }
}

/**
 * Get the client ip address.
 *
 * @return string IP address
 */
function getClientIpServer()
{
    if (getenv('HTTP_CLIENT_IP')) {
        $ipaddress = getenv('HTTP_CLIENT_IP');
    } elseif (getenv('HTTP_X_FORWARDED_FOR')) {
        $ipaddress = getenv('HTTP_X_FORWARDED_FOR');
    } elseif (getenv('HTTP_X_FORWARDED')) {
        $ipaddress = getenv('HTTP_X_FORWARDED');
    } elseif (getenv('HTTP_FORWARDED_FOR')) {
        $ipaddress = getenv('HTTP_FORWARDED_FOR');
    } elseif (getenv('HTTP_FORWARDED')) {
        $ipaddress = getenv('HTTP_FORWARDED');
    } elseif (getenv('REMOTE_ADDR')) {
        $ipaddress = getenv('REMOTE_ADDR');
    } else {
        $ipaddress = 'UNKNOWN';
    }

    return $ipaddress;
}

/**
 * Escape all HTML, JavaScript, and CSS.
 *
 * @param string $input    The input string
 * @param string $encoding Which character encoding are we using?
 *
 * @return string
 */
function noHTML($input, $encoding = 'UTF-8')
{
    return htmlspecialchars($input, ENT_QUOTES | ENT_XHTML, $encoding, false);
}

/**
 * handleConfigFile().
 *
 * permits to handle the Teampass config file
 * $action accepts "rebuild" and "update"
 */
function handleConfigFile($action, $field = null, $value = null)
{
    global $server, $user, $pass, $database, $port, $encoding;
    global $SETTINGS;

    $tp_config_file = '../includes/config/tp.config.php';

    // include librairies & connect to DB
    include_once $SETTINGS['cpassman_dir'].'/includes/libraries/Database/Meekrodb/db.class.php';
    if (defined('DB_PASSWD_CLEAR') === false) {
        define('DB_PASSWD_CLEAR', defuseReturnDecrypted(DB_PASSWD, $SETTINGS));
    }
    DB::$host = DB_HOST;
    DB::$user = DB_USER;
    DB::$password = DB_PASSWD_CLEAR;
    DB::$dbName = DB_NAME;
    DB::$port = DB_PORT;
    DB::$encoding = DB_ENCODING;
    //$link = mysqli_connect(DB_HOST, DB_USER, DB_PASSWD_CLEAR, DB_NAME, DB_PORT);
    //$link->set_charset(DB_ENCODING);

    if (file_exists($tp_config_file) === false || $action === 'rebuild') {
        // perform a copy
        if (file_exists($tp_config_file)) {
            if (!copy($tp_config_file, $tp_config_file.'.'.date('Y_m_d_His', time()))) {
                return "ERROR: Could not copy file '".$tp_config_file."'";
            }
        }

        // regenerate
        $data = array();
        $data[0] = "<?php\n";
        $data[1] = "global \$SETTINGS;\n";
        $data[2] = "\$SETTINGS = array (\n";
        $rows = DB::query(
            'SELECT * FROM '.prefixTable('misc').' WHERE type=%s',
            'admin'
        );
        foreach ($rows as $record) {
            array_push($data, "    '".$record['intitule']."' => '".$record['valeur']."',\n");
        }
        array_push($data, ");\n");
        $data = array_unique($data);
    } elseif ($action === 'update' && empty($field) === false) {
        $data = file($tp_config_file);
        $inc = 0;
        $bFound = false;
        foreach ($data as $line) {
            if (stristr($line, ');')) {
                break;
            }

            if (stristr($line, "'".$field."' => '")) {
                $data[$inc] = "    '".$field."' => '".filter_var($value, FILTER_SANITIZE_STRING)."',\n";
                $bFound = true;
                break;
            }
            ++$inc;
        }
        if ($bFound === false) {
            $data[($inc)] = "    '".$field."' => '".filter_var($value, FILTER_SANITIZE_STRING)."',\n);\n";
        }
    }

    // update file
    file_put_contents($tp_config_file, implode('', isset($data) ? $data : array()));

    return true;
}

/*
** Permits to replace &#92; to permit correct display
*/
/**
 * @param string $input
 */
function handleBackslash($input)
{
    return str_replace('&amp;#92;', '&#92;', $input);
}

/*
** Permits to loas settings
*/
function loadSettings()
{
    global $SETTINGS;

    /* LOAD CPASSMAN SETTINGS */
    if (!isset($SETTINGS['loaded']) || $SETTINGS['loaded'] != 1) {
        $SETTINGS['duplicate_folder'] = 0; //by default, this is set to 0;
        $SETTINGS['duplicate_item'] = 0; //by default, this is set to 0;
        $SETTINGS['number_of_used_pw'] = 5; //by default, this value is set to 5;
        $settings = array();

        $rows = DB::query(
            'SELECT * FROM '.prefixTable('misc').' WHERE type=%s_type OR type=%s_type2',
            array(
                'type' => 'admin',
                'type2' => 'settings',
            )
        );
        foreach ($rows as $record) {
            if ($record['type'] === 'admin') {
                $SETTINGS[$record['intitule']] = $record['valeur'];
            } else {
                $settings[$record['intitule']] = $record['valeur'];
            }
        }
        $SETTINGS['loaded'] = 1;
        $SETTINGS['default_session_expiration_time'] = 5;
    }
}

/*
** check if folder has custom fields.
** Ensure that target one also has same custom fields
*/
function checkCFconsistency($source_id, $target_id)
{
    $source_cf = array();
    $rows = DB::QUERY(
        'SELECT id_category
        FROM '.prefixTable('categories_folders').'
        WHERE id_folder = %i',
        $source_id
    );
    foreach ($rows as $record) {
        array_push($source_cf, $record['id_category']);
    }

    $target_cf = array();
    $rows = DB::QUERY(
        'SELECT id_category
        FROM '.prefixTable('categories_folders').'
        WHERE id_folder = %i',
        $target_id
    );
    foreach ($rows as $record) {
        array_push($target_cf, $record['id_category']);
    }

    $cf_diff = array_diff($source_cf, $target_cf);
    if (count($cf_diff) > 0) {
        return false;
    }

    return true;
}

/**
 * Will encrypte/decrypt a fil eusing Defuse.
 *
 * @param string $type        can be either encrypt or decrypt
 * @param string $source_file path to source file
 * @param string $target_file path to target file
 * @param array  $SETTINGS    Settings
 * @param string $password    A password
 *
 * @return string|bool
 */
function prepareFileWithDefuse(
    $type,
    $source_file,
    $target_file,
    $SETTINGS,
    $password = null
) {
    // Load AntiXSS
    include_once $SETTINGS['cpassman_dir'].'/includes/libraries/protect/AntiXSS/AntiXSS.php';
    $antiXss = new protect\AntiXSS\AntiXSS();

    // Protect against bad inputs
    if (is_array($source_file) === true || is_array($target_file) === true) {
        return 'error_cannot_be_array';
    }

    // Sanitize
    $source_file = $antiXss->xss_clean($source_file);
    $target_file = $antiXss->xss_clean($target_file);

    if (empty($password) === true || is_null($password) === true) {
        /*
        File encryption/decryption is done with the SALTKEY
         */

        // get KEY
        $ascii_key = file_get_contents(SECUREPATH.'/teampass-seckey.txt');

        // Now perform action on the file
        $err = '';
        if ($type === 'decrypt') {
            // Decrypt file
            $err = defuseFileDecrypt(
                $source_file,
                $target_file,
                \Defuse\Crypto\Key::loadFromAsciiSafeString($ascii_key),
                $SETTINGS
            );
        // ---
        } elseif ($type === 'encrypt') {
            // Encrypt file
            $err = defuseFileEncrypt(
                $source_file,
                $target_file,
                \Defuse\Crypto\Key::loadFromAsciiSafeString($ascii_key),
                $SETTINGS
            );
        }
    } else {
        /*
        File encryption/decryption is done with special password and not the SALTKEY
         */

        $err = '';
        if ($type === 'decrypt') {
            // Decrypt file
            $err = defuseFileDecrypt(
                $source_file,
                $target_file,
                $password,
                $SETTINGS
            );
        // ---
        } elseif ($type === 'encrypt') {
            // Encrypt file
            $err = defuseFileEncrypt(
                $source_file,
                $target_file,
                $password,
                $SETTINGS
            );
        }
    }

    // return error
    return empty($err) === false ? $err : true;
}

/**
 * Encrypt a file with Defuse.
 *
 * @param string $source_file path to source file
 * @param string $target_file path to target file
 * @param array  $SETTINGS    Settings
 * @param string $password    A password
 *
 * @return string|bool
 */
function defuseFileEncrypt(
    $source_file,
    $target_file,
    $SETTINGS,
    $password = null
) {
    // load PhpEncryption library
    $path_to_encryption = '/includes/libraries/Encryption/Encryption/';
    include_once $SETTINGS['cpassman_dir'].$path_to_encryption.'Crypto.php';
    include_once $SETTINGS['cpassman_dir'].$path_to_encryption.'Encoding.php';
    include_once $SETTINGS['cpassman_dir'].$path_to_encryption.'DerivedKeys.php';
    include_once $SETTINGS['cpassman_dir'].$path_to_encryption.'Key.php';
    include_once $SETTINGS['cpassman_dir'].$path_to_encryption.'KeyOrPassword.php';
    include_once $SETTINGS['cpassman_dir'].$path_to_encryption.'File.php';
    include_once $SETTINGS['cpassman_dir'].$path_to_encryption.'RuntimeTests.php';
    include_once $SETTINGS['cpassman_dir'].$path_to_encryption.'KeyProtectedByPassword.php';
    include_once $SETTINGS['cpassman_dir'].$path_to_encryption.'Core.php';

    try {
        \Defuse\Crypto\File::encryptFileWithPassword(
            $source_file,
            $target_file,
            $password
        );
    } catch (Defuse\Crypto\Exception\WrongKeyOrModifiedCiphertextException $ex) {
        $err = 'wrong_key';
    } catch (Defuse\Crypto\Exception\EnvironmentIsBrokenException $ex) {
        $err = $ex;
    } catch (Defuse\Crypto\Exception\IOException $ex) {
        $err = $ex;
    }

    // return error
    return empty($err) === false ? $err : true;
}

/**
 * Decrypt a file with Defuse.
 *
 * @param string $source_file path to source file
 * @param string $target_file path to target file
 * @param array  $SETTINGS    Settings
 * @param string $password    A password
 *
 * @return string|bool
 */
function defuseFileDecrypt(
    $source_file,
    $target_file,
    $SETTINGS,
    $password = null
) {
    // load PhpEncryption library
    $path_to_encryption = '/includes/libraries/Encryption/Encryption/';
    include_once $SETTINGS['cpassman_dir'].$path_to_encryption.'Crypto.php';
    include_once $SETTINGS['cpassman_dir'].$path_to_encryption.'Encoding.php';
    include_once $SETTINGS['cpassman_dir'].$path_to_encryption.'DerivedKeys.php';
    include_once $SETTINGS['cpassman_dir'].$path_to_encryption.'Key.php';
    include_once $SETTINGS['cpassman_dir'].$path_to_encryption.'KeyOrPassword.php';
    include_once $SETTINGS['cpassman_dir'].$path_to_encryption.'File.php';
    include_once $SETTINGS['cpassman_dir'].$path_to_encryption.'RuntimeTests.php';
    include_once $SETTINGS['cpassman_dir'].$path_to_encryption.'KeyProtectedByPassword.php';
    include_once $SETTINGS['cpassman_dir'].$path_to_encryption.'Core.php';

    try {
        \Defuse\Crypto\File::decryptFileWithPassword(
            $source_file,
            $target_file,
            $password
        );
    } catch (Defuse\Crypto\Exception\WrongKeyOrModifiedCiphertextException $ex) {
        $err = 'wrong_key';
    } catch (Defuse\Crypto\Exception\EnvironmentIsBrokenException $ex) {
        $err = $ex;
    } catch (Defuse\Crypto\Exception\IOException $ex) {
        $err = $ex;
    }

    // return error
    return empty($err) === false ? $err : true;
}

/*
* NOT TO BE USED
*/
/**
 * Undocumented function.
 *
 * @param string $text Text to debug
 */
function debugTeampass($text)
{
    $debugFile = fopen('D:/wamp64/www/TeamPass/debug.txt', 'r+');
    fputs($debugFile, $text);
    fclose($debugFile);
}

/**
 * DELETE the file with expected command depending on server type.
 *
 * @param string $file     Path to file
 * @param array  $SETTINGS Teampass settings
 */
function fileDelete($file, $SETTINGS)
{
    // Load AntiXSS
    include_once $SETTINGS['cpassman_dir'].'/includes/libraries/protect/AntiXSS/AntiXSS.php';
    $antiXss = new protect\AntiXSS\AntiXSS();

    $file = $antiXss->xss_clean($file);
    if (is_file($file)) {
        unlink($file);
    }
}

/**
 * Permits to extract the file extension.
 *
 * @param string $file File name
 *
 * @return string
 */
function getFileExtension($file)
{
    if (strpos($file, '.') === false) {
        return $file;
    }

    return substr($file, strrpos($file, '.') + 1);
}

/**
 * Performs chmod operation on subfolders.
 *
 * @param string $dir             Parent folder
 * @param int    $dirPermissions  New permission on folders
 * @param int    $filePermissions New permission on files
 *
 * @return bool
 */
function chmodRecursive($dir, $dirPermissions, $filePermissions)
{
    $pointer_dir = opendir($dir);
    $res = true;
    while (false !== ($file = readdir($pointer_dir))) {
        if (($file === '.') || ($file === '..')) {
            continue;
        }

        $fullPath = $dir.'/'.$file;

        if (is_dir($fullPath)) {
            if ($res = @chmod($fullPath, $dirPermissions)) {
                $res = @chmodRecursive($fullPath, $dirPermissions, $filePermissions);
            }
        } else {
            $res = chmod($fullPath, $filePermissions);
        }
        if (!$res) {
            closedir($pointer_dir);

            return false;
        }
    }
    closedir($pointer_dir);
    if (is_dir($dir) && $res) {
        $res = @chmod($dir, $dirPermissions);
    }

    return $res;
}

/**
 * Check if user can access to this item.
 *
 * @param int $item_id ID of item
 */
function accessToItemIsGranted($item_id)
{
    global $SETTINGS;

    include_once $SETTINGS['cpassman_dir'].'/includes/libraries/protect/SuperGlobal/SuperGlobal.php';
    $superGlobal = new protect\SuperGlobal\SuperGlobal();

    // Prepare superGlobal variables
    $session_groupes_visibles = $superGlobal->get('groupes_visibles', 'SESSION');
    $session_list_restricted_folders_for_items = $superGlobal->get('list_restricted_folders_for_items', 'SESSION');

    // Load item data
    $data = DB::queryFirstRow(
        'SELECT id_tree
        FROM '.prefixTable('items').'
        WHERE id = %i',
        $item_id
    );

    // Check if user can access this folder
    if (in_array($data['id_tree'], $session_groupes_visibles) === false) {
        // Now check if this folder is restricted to user
        if (isset($session_list_restricted_folders_for_items[$data['id_tree']])
            && !in_array($item_id, $session_list_restricted_folders_for_items[$data['id_tree']])
        ) {
            return 'ERR_FOLDER_NOT_ALLOWED';
        } else {
            return 'ERR_FOLDER_NOT_ALLOWED';
        }
    }

    return true;
}

/**
 * Creates a unique key.
 *
 * @param float $lenght Key lenght
 *
 * @return string
 */
function uniqidReal($lenght = 13)
{
    // uniqid gives 13 chars, but you could adjust it to your needs.
    if (function_exists('random_bytes')) {
        $bytes = random_bytes(ceil($lenght / 2));
    } elseif (function_exists('openssl_random_pseudo_bytes')) {
        $bytes = openssl_random_pseudo_bytes(ceil($lenght / 2));
    } else {
        throw new Exception('no cryptographically secure random function available');
    }

    return substr(bin2hex($bytes), 0, $lenght);
}

/**
 * Obfuscate an email.
 *
 * @param string $email Email address
 *
 * @return string
 */
function obfuscateEmail($email)
{
    $prop = 2;
    $start = '';
    $end = '';
    $domain = substr(strrchr($email, '@'), 1);
    $mailname = str_replace($domain, '', $email);
    $name_l = strlen($mailname);
    $domain_l = strlen($domain);
    for ($i = 0; $i <= $name_l / $prop - 1; ++$i) {
        $start .= 'x';
    }

    for ($i = 0; $i <= $domain_l / $prop - 1; ++$i) {
        $end .= 'x';
    }

    return substr_replace($mailname, $start, 2, $name_l / $prop)
        .substr_replace($domain, $end, 2, $domain_l / $prop);
}

/**
 * Permits to get LDAP information about a user.
 *
 * @param string $username User name
 * @param string $password User password
 * @param array  $SETTINGS Settings
 *
 * @return string
 */
function connectLDAP($username, $password, $SETTINGS)
{
    $ldapInfo = '';

    // Prepare LDAP connection if set up

    if ($SETTINGS['ldap_type'] === 'posix-search') {
        $ldapInfo = ldapPosixSearch(
            $username,
            $password,
            $SETTINGS
        );
    } else {
        $ldapInfo = ldapPosixAndWindows(
            $username,
            $password,
            $SETTINGS
        );
    }

    return json_encode($ldapInfo);
}

/**
 * Undocumented function.
 *
 * @param string $username Username
 * @param string $password Password
 * @param array  $SETTINGS Settings
 *
 * @return array
 */
function ldapPosixSearch($username, $password, $SETTINGS)
{
    $ldapURIs = '';
    $user_email = '';
    $user_found = false;
    $user_lastname = '';
    $user_name = '';
    $ldapConnection = false;

    foreach (explode(',', $SETTINGS['ldap_domain_controler']) as $domainControler) {
        if ($SETTINGS['ldap_ssl'] == 1) {
            $ldapURIs .= 'ldaps://'.$domainControler.':'.$SETTINGS['ldap_port'].' ';
        } else {
            $ldapURIs .= 'ldap://'.$domainControler.':'.$SETTINGS['ldap_port'].' ';
        }
    }
    $ldapconn = ldap_connect($ldapURIs);

    if ($SETTINGS['ldap_tls']) {
        ldap_start_tls($ldapconn);
    }
    ldap_set_option($ldapconn, LDAP_OPT_PROTOCOL_VERSION, 3);
    ldap_set_option($ldapconn, LDAP_OPT_REFERRALS, 0);

    // Is LDAP connection ready?
    if ($ldapconn !== false) {
        // Should we bind the connection?
        if (empty($SETTINGS['ldap_bind_dn']) === false
            && empty($SETTINGS['ldap_bind_passwd']) === false
        ) {
            $ldapbind = ldap_bind($ldapconn, $SETTINGS['ldap_bind_dn'], $SETTINGS['ldap_bind_passwd']);
        } else {
            $ldapbind = false;
        }
        if ((empty($SETTINGS['ldap_bind_dn']) === true && empty($SETTINGS['ldap_bind_passwd']) === true)
            || $ldapbind === true
        ) {
            $filter = '(&('.$SETTINGS['ldap_user_attribute'].'='.$username.')(objectClass='.$SETTINGS['ldap_object_class'].'))';
            $result = ldap_search(
                $ldapconn,
                $SETTINGS['ldap_search_base'],
                $filter,
                array('dn', 'mail', 'givenname', 'sn', 'samaccountname')
            );

            // Check if user was found in AD
            if (ldap_count_entries($ldapconn, $result) > 0) {
                // Get user's info and especially the DN
                $result = ldap_get_entries($ldapconn, $result);
                $user_dn = $result[0]['dn'];
                $user_email = $result[0]['mail'][0];
                $user_lastname = $result[0]['sn'][0];
                $user_name = isset($result[0]['givenname'][0]) === true ? $result[0]['givenname'][0] : '';
                $user_found = true;

                // Should we restrain the search in specified user groups
                $GroupRestrictionEnabled = false;
                if (isset($SETTINGS['ldap_usergroup']) === true
                    && empty($SETTINGS['ldap_usergroup']) === false
                ) {
                    // New way to check User's group membership
                    $filter_group = 'memberUid='.$username;
                    $result_group = ldap_search(
                        $ldapconn,
                        $SETTINGS['ldap_search_base'],
                        $filter_group,
                        array('dn', 'samaccountname')
                    );

                    if ($result_group) {
                        $entries = ldap_get_entries($ldapconn, $result_group);

                        if ($entries['count'] > 0) {
                            // Now check if group fits
                            for ($i = 0; $i < $entries['count']; ++$i) {
                                $parsr = ldap_explode_dn($entries[$i]['dn'], 0);
                                if (str_replace(array('CN=', 'cn='), '', $parsr[0]) === $SETTINGS['ldap_usergroup']) {
                                    $GroupRestrictionEnabled = true;
                                    break;
                                }
                            }
                        }
                    }
                }

                // Is user in the LDAP?
                if ($GroupRestrictionEnabled === true
                    || ($GroupRestrictionEnabled === false
                    && (isset($SETTINGS['ldap_usergroup']) === false
                    || (isset($SETTINGS['ldap_usergroup']) === true
                    && empty($SETTINGS['ldap_usergroup']) === true)))
                ) {
                    // Try to auth inside LDAP
                    $ldapbind = ldap_bind($ldapconn, $user_dn, $password);
                    if ($ldapbind === true) {
                        $ldapConnection = true;
                    } else {
                        $ldapConnection = false;
                    }
                }
            } else {
                $ldapConnection = false;
            }
        } else {
            $ldapConnection = false;
        }
    } else {
        $ldapConnection = false;
    }

    return array(
        'lastname' => $user_lastname,
        'name' => $user_name,
        'email' => $user_email,
        'auth_success' => $ldapConnection,
        'user_found' => $user_found,
    );
}

/**
 * Undocumented function.
 *
 * @param string $username Username
 * @param string $password Password
 * @param array  $SETTINGS Settings
 *
 * @return array
 */
function ldapPosixAndWindows($username, $password, $SETTINGS)
{
    $user_email = '';
    $user_found = false;
    $user_lastname = '';
    $user_name = '';
    $ldapConnection = false;
    $ldap_suffix = '';

    //Multiple Domain Names
    if (strpos(html_entity_decode($username), '\\') === true) {
        $ldap_suffix = '@'.substr(html_entity_decode($username), 0, strpos(html_entity_decode($username), '\\'));
        $username = substr(html_entity_decode($username), strpos(html_entity_decode($username), '\\') + 1);
    }
    //load ClassLoader
    include_once $SETTINGS['cpassman_dir'].'/sources/SplClassLoader.php';

    $adldap = new SplClassLoader('adLDAP', '../includes/libraries/LDAP');
    $adldap->register();

    // Posix style LDAP handles user searches a bit differently
    if ($SETTINGS['ldap_type'] === 'posix') {
        $ldap_suffix = ','.$SETTINGS['ldap_suffix'].','.$SETTINGS['ldap_domain_dn'];
    } else {
        // case where $SETTINGS['ldap_type'] equals 'windows'
        //Multiple Domain Names
        $ldap_suffix = $SETTINGS['ldap_suffix'];
    }

    // Ensure no double commas exist in ldap_suffix
    $ldap_suffix = str_replace(',,', ',', $ldap_suffix);

    // Create LDAP connection
    $adldap = new adLDAP\adLDAP(
        array(
            'base_dn' => $SETTINGS['ldap_domain_dn'],
            'account_suffix' => $ldap_suffix,
            'domain_controllers' => explode(',', $SETTINGS['ldap_domain_controler']),
            'ad_port' => $SETTINGS['ldap_port'],
            'use_ssl' => $SETTINGS['ldap_ssl'],
            'use_tls' => $SETTINGS['ldap_tls'],
        )
    );

    // OpenLDAP expects an attribute=value pair
    if ($SETTINGS['ldap_type'] === 'posix') {
        $auth_username = $SETTINGS['ldap_user_attribute'].'='.$username;
    } else {
        $auth_username = $username;
    }

    // Authenticate the user
    if ($adldap->authenticate($auth_username, html_entity_decode($password))) {
        // Get user info
        $result = $adldap->user()->info($auth_username, array('mail', 'givenname', 'sn'));
        $user_email = $result[0]['mail'][0];
        $user_lastname = $result[0]['sn'][0];
        $user_name = $result[0]['givenname'][0];
        $user_found = true;

        // Is user in allowed group
        if (isset($SETTINGS['ldap_allowed_usergroup']) === true
            && empty($SETTINGS['ldap_allowed_usergroup']) === false
        ) {
            if ($adldap->user()->inGroup($auth_username, $SETTINGS['ldap_allowed_usergroup']) === true) {
                $ldapConnection = true;
            } else {
                $ldapConnection = false;
            }
        } else {
            $ldapConnection = true;
        }
    } else {
        $ldapConnection = false;
    }

    return array(
        'lastname' => $user_lastname,
        'name' => $user_name,
        'email' => $user_email,
        'auth_success' => $ldapConnection,
        'user_found' => $user_found,
    );
}

//--------------------------------

/**
 * Perform a Query.
 *
 * @param array  $SETTINGS Teamapss settings
 * @param string $fields   Fields to use
 * @param string $table    Table to use
 *
 * @return array
 */
function performDBQuery($SETTINGS, $fields, $table)
{
    // include librairies & connect to DB
    include_once $SETTINGS['cpassman_dir'].'/includes/config/settings.php';
    include_once $SETTINGS['cpassman_dir'].'/includes/libraries/Database/Meekrodb/db.class.php';
    if (defined('DB_PASSWD_CLEAR') === false) {
        define('DB_PASSWD_CLEAR', defuseReturnDecrypted(DB_PASSWD, $SETTINGS));
    }
    DB::$host = DB_HOST;
    DB::$user = DB_USER;
    DB::$password = DB_PASSWD_CLEAR;
    DB::$dbName = DB_NAME;
    DB::$port = DB_PORT;
    DB::$encoding = DB_ENCODING;
    //$link = mysqli_connect(DB_HOST, DB_USER, DB_PASSWD_CLEAR, DB_NAME, DB_PORT);
    //$link->set_charset(DB_ENCODING);

    // Insert log in DB
    return DB::query(
        'SELECT '.$fields.'
        FROM '.prefixTable($table)
    );
}

/**
 * Undocumented function.
 *
 * @param int $bytes Size of file
 *
 * @return string
 */
function formatSizeUnits($bytes)
{
    if ($bytes >= 1073741824) {
        $bytes = number_format($bytes / 1073741824, 2).' GB';
    } elseif ($bytes >= 1048576) {
        $bytes = number_format($bytes / 1048576, 2).' MB';
    } elseif ($bytes >= 1024) {
        $bytes = number_format($bytes / 1024, 2).' KB';
    } elseif ($bytes > 1) {
        $bytes = $bytes.' bytes';
    } elseif ($bytes == 1) {
        $bytes = $bytes.' byte';
    } else {
        $bytes = '0 bytes';
    }

    return $bytes;
}

/**
 * Generate user pair of keys.
 *
 * @param string $userPwd User password
 *
 * @return array
 */
function generateUserKeys($userPwd)
{
    // include library
    include_once '../includes/libraries/Encryption/phpseclib/Math/BigInteger.php';
    include_once '../includes/libraries/Encryption/phpseclib/Crypt/RSA.php';
    include_once '../includes/libraries/Encryption/phpseclib/Crypt/AES.php';

    // Load classes
    $rsa = new Crypt_RSA();
    $cipher = new Crypt_AES();

    // Create the private and public key
    $res = $rsa->createKey(4096);

    // Encrypt the privatekey
    $cipher->setPassword($userPwd);
    $privatekey = $cipher->encrypt($res['privatekey']);

    return array(
        'private_key' => base64_encode($privatekey),
        'public_key' => base64_encode($res['publickey']),
        'private_key_clear' => base64_encode($res['privatekey']),
    );
}

/**
 * Permits to decrypt the user's privatekey.
 *
 * @param string $userPwd        User password
 * @param string $userPrivateKey User private key
 *
 * @return string
 */
function decryptPrivateKey($userPwd, $userPrivateKey)
{
    if (empty($userPwd) === false) {
        include_once '../includes/libraries/Encryption/phpseclib/Crypt/AES.php';

        // Load classes
        $cipher = new Crypt_AES();

        // Encrypt the privatekey
        $cipher->setPassword($userPwd);

        return base64_encode($cipher->decrypt(base64_decode($userPrivateKey)));
    }
}

/**
 * Permits to encrypt the user's privatekey.
 *
 * @param string $userPwd        User password
 * @param string $userPrivateKey User private key
 *
 * @return string
 */
function encryptPrivateKey($userPwd, $userPrivateKey)
{
    if (empty($userPwd) === false) {
        include_once '../includes/libraries/Encryption/phpseclib/Crypt/AES.php';

        // Load classes
        $cipher = new Crypt_AES();

        // Encrypt the privatekey
        $cipher->setPassword($userPwd);

        return base64_encode($cipher->encrypt(base64_decode($userPrivateKey)));
    }
}

/**
 * Performs an AES encryption of the.
 *
 * @param string $userPwd        User password
 * @param string $userPrivateKey User private key
 *
 * @return string
 */
/*function encryptData($userPwd, $userPrivateKey)
{
    if (empty($userPwd) === false) {
        include_once '../includes/libraries/Encryption/phpseclib/Crypt/AES.php';

        // Load classes
        $cipher = new Crypt_AES();

        // Encrypt the privatekey
        $cipher->setPassword($userPwd);

        return $cipher->decrypt(base64_decode($userPrivateKey));
    }
}
*/

/**
 * Generate a key.
 *
 * @param int $length Length of the key to generate
 *
 * @return string
 */
/*
function randomStr($length)
{
    $keyspace = str_shuffle('0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ');
    $pieces = [];
    $max = mb_strlen($keyspace, '8bit') - 1;
    for ($i = 0; $i < $length; ++$i) {
        $pieces[] = $keyspace[random_int(0, $max)];
    }

    return implode('', $pieces);
}
*/

/**
 * Encrypts a string using AES.
 *
 * @param string $data String to encrypt
 *
 * @return array
 */
function doDataEncryption($data)
{
    // Includes
    include_once '../includes/libraries/Encryption/phpseclib/Crypt/AES.php';

    // Load classes
    $cipher = new Crypt_AES(CRYPT_AES_MODE_CBC);

    // Generate an object key
    $objectKey = uniqidReal(32);

    // Set it as password
    $cipher->setPassword($objectKey);

    return array(
        'encrypted' => base64_encode($cipher->encrypt($data)),
        'objectKey' => base64_encode($objectKey),
    );
}

/**
 * Decrypts a string using AES.
 *
 * @param string $data Encrypted data
 * @param string $key  Key to uncrypt
 *
 * @return string
 */
function doDataDecryption($data, $key)
{
    // Includes
    include_once '../includes/libraries/Encryption/phpseclib/Crypt/AES.php';

    // Load classes
    $cipher = new Crypt_AES();

    // Set the object key
    $cipher->setPassword(base64_decode($key));

    return base64_encode($cipher->decrypt(base64_decode($data)));
}

/**
 * Encrypts using RSA a string using a public key.
 *
 * @param string $key       Key to be encrypted
 * @param string $publicKey User public key
 *
 * @return string
 */
function encryptUserObjectKey($key, $publicKey)
{
    // Includes
    include_once '../includes/libraries/Encryption/phpseclib/Math/BigInteger.php';
    include_once '../includes/libraries/Encryption/phpseclib/Crypt/RSA.php';

    // Load classes
    $rsa = new Crypt_RSA();
    $rsa->loadKey(base64_decode($publicKey));

    // Encrypt
    return base64_encode($rsa->encrypt(base64_decode($key)));
}

/**
 * Decrypts using RSA an encrypted string using a private key.
 *
 * @param string $key        Encrypted key
 * @param string $privateKey User private key
 *
 * @return string
 */
function decryptUserObjectKey($key, $privateKey)
{
    // Includes
    include_once '../includes/libraries/Encryption/phpseclib/Math/BigInteger.php';
    include_once '../includes/libraries/Encryption/phpseclib/Crypt/RSA.php';

    // Load classes
    $rsa = new Crypt_RSA();
    $rsa->loadKey(base64_decode($privateKey));

    // Decrypt
    return base64_encode($rsa->decrypt(base64_decode($key)));
}

/**
 * Encrypts a file.
 *
 * @param string $fileInName File name
 * @param string $fileInPath Path to file
 *
 * @return array
 */
function encryptFile($fileInName, $fileInPath)
{
    if (defined('FILE_BUFFER_SIZE') === false) {
        define('FILE_BUFFER_SIZE', 128 * 1024);
    }

    // Includes
    include_once '../includes/config/include.php';
    include_once '../includes/libraries/Encryption/phpseclib/Math/BigInteger.php';
    include_once '../includes/libraries/Encryption/phpseclib/Crypt/RSA.php';
    include_once '../includes/libraries/Encryption/phpseclib/Crypt/AES.php';

    // Load classes
    $cipher = new Crypt_AES();

    // Generate an object key
    $objectKey = uniqidReal(32);

    // Set it as password
    $cipher->setPassword($objectKey);

    // Prevent against out of memory
    $cipher->enableContinuousBuffer();
    $cipher->disablePadding();

    // Encrypt the file content
    $plaintext = file_get_contents(
        filter_var($fileInPath.'/'.$fileInName, FILTER_SANITIZE_URL)
    );
    $ciphertext = $cipher->encrypt($plaintext);

    // Save new file
    $hash = md5($plaintext);
    $fileOut = $fileInPath.'/'.TP_FILE_PREFIX.$hash;
    file_put_contents($fileOut, $ciphertext);
    unlink($fileInPath.'/'.$fileInName);

    return array(
        'fileHash' => base64_encode($hash),
        'objectKey' => base64_encode($objectKey),
    );
}

/**
 * Decrypt a file.
 *
 * @param string $fileName File name
 * @param string $filePath Path to file
 * @param string $key      Key to use
 *
 * @return string
 */
function decryptFile($fileName, $filePath, $key)
{
    define('FILE_BUFFER_SIZE', 128 * 1024);

    // Includes
    include_once '../includes/config/include.php';
    include_once '../includes/libraries/Encryption/phpseclib/Math/BigInteger.php';
    include_once '../includes/libraries/Encryption/phpseclib/Crypt/RSA.php';
    include_once '../includes/libraries/Encryption/phpseclib/Crypt/AES.php';

    // Get file name
    $fileName = base64_decode($fileName);

    // Load classes
    $cipher = new Crypt_AES();

    // Set the object key
    $cipher->setPassword(base64_decode($key));

    // Prevent against out of memory
    $cipher->enableContinuousBuffer();
    $cipher->disablePadding();

    // Get file content
    $ciphertext = file_get_contents($filePath.'/'.TP_FILE_PREFIX.$fileName);

    // Decrypt file content and return
    return base64_encode($cipher->decrypt($ciphertext));
}

/**
 * Undocumented function.
 *
 * @param int $length Length of password
 *
 * @return string
 */
function generateQuickPassword($length = 16, $symbolsincluded = true)
{
    // Generate new user password
    $small_letters = range('a', 'z');
    $big_letters = range('A', 'Z');
    $digits = range(0, 9);
    $symbols = $symbolsincluded === true ?
        array('#', '_', '-', '@', '$', '+', '&') :
        array();

    $res = array_merge($small_letters, $big_letters, $digits, $symbols);
    $c = count($res);
    // first variant

    $random_string = '';
    for ($i = 0; $i < $length; ++$i) {
        $random_string .= $res[random_int(0, $c - 1)];
    }

    return $random_string;
}

/**
 * Permit to store the sharekey of an object for users.
 *
 * @param string $object_name             Type for table selection
 * @param int    $post_folder_is_personal Personal
 * @param int    $post_folder_id          Folder
 * @param int    $post_object_id          Object
 * @param string $objectKey               Object key
 * @param array  $SETTINGS                Teampass settings
 */
function storeUsersShareKey(
    $object_name,
    $post_folder_is_personal,
    $post_folder_id,
    $post_object_id,
    $objectKey,
    $SETTINGS
) {
    // include librairies & connect to DB
    include_once $SETTINGS['cpassman_dir'].'/includes/config/settings.php';
    include_once $SETTINGS['cpassman_dir'].'/includes/libraries/Database/Meekrodb/db.class.php';
    if (defined('DB_PASSWD_CLEAR') === false) {
        define('DB_PASSWD_CLEAR', defuseReturnDecrypted(DB_PASSWD, $SETTINGS));
    }
    DB::$host = DB_HOST;
    DB::$user = DB_USER;
    DB::$password = DB_PASSWD_CLEAR;
    DB::$dbName = DB_NAME;
    DB::$port = DB_PORT;
    DB::$encoding = DB_ENCODING;

    // Delete existing entries for this object
    db::debugmode(true);
    DB::delete(
        $object_name,
        'object_id = %i',
        $post_object_id
    );
    db::debugmode(false);
    echo " -- ".$post_folder_is_personal." -- ";

    if ((int) $post_folder_is_personal === 1
        && in_array($post_folder_id, $_SESSION['personal_folders']) === true
    ) {
        // If this is a personal object
        // Only create the sharekey for user
        DB::insert(
            $object_name,
            array(
                'object_id' => $post_object_id,
                'user_id' => $_SESSION['user_id'],
                'share_key' => encryptUserObjectKey($objectKey, $_SESSION['user']['public_key']),
            )
        );
    } else {
        // This is a public object
        // Create sharekey for each user
        $users = DB::query(
            'SELECT id, public_key
            FROM '.prefixTable('users').'
            WHERE id NOT IN ("'.OTV_USER_ID.'","'.SSH_USER_ID.'","'.API_USER_ID.'")
            AND public_key != ""'
        );
        foreach ($users as $user) {
            // Insert in DB the new object key for this item by user
            DB::insert(
                $object_name,
                array(
                    'object_id' => $post_object_id,
                    'user_id' => $user['id'],
                    'share_key' => encryptUserObjectKey(
                        $objectKey,
                        $user['public_key']
                    ),
                )
            );
        }
    }
}

/**
 * Is this string base64 encoded?
 *
 * @param string $str Encoded string?
 *
 * @return bool
 */
function isBase64($str)
{
    $str = (string) trim($str);

    if (!isset($str[0])) {
        return false;
    }

    $base64String = (string) base64_decode($str, true);
    if ($base64String && base64_encode($base64String) === $str) {
        return true;
    }

    return false;
}
