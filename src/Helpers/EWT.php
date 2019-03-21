<?php declare(strict_types=1);

/**
 * Encrypted Web Token Class
 *
 * Builds on the concept of JWT's, but provides an encrypted payload so the user
 * who uses it does not see the contents of it. Also simpler than JWE tokens.
 *
 * Also does not allow for NONE as algorithm. Should be secure against user-changed
 * algorithm as all signatures are always verified.
 *
 * Encoding
 * --------
 *   Encoding creates a JSON structure with data:
 *   {
 *     "i": "...",            // Initialization Vector
 *     "a": "aes-256-gcm",    // OpenSSL Cipher name (aes-256-gcm)
 *     "h": "sha256",         // MAC algorithm name (sha256)
 *     "s": "...",            // Signature = HMAC(algo, plain_payload + secret );
 *     "p": "..."             // Encrypted payload as binHex string
 *   }
 *
 *   This JSON document is then Base64 encoded and put as the header in the HTTP requests/responses.
 *   Header name used is up to the implementers. Possible header names "X-EWT", "X-EWToken"
 *
 *
 * Decoding
 * --------
 *   The decoding part takes the B64 encoded string produced in encode() and decodes the
 *   JSON structure and then the payload. If the Signature matches then the payload is
 *   returned, otherwise NULL is returned.
 *
 * @package  Spin
 */
#################################################################################################################################
/*
v2 - JWT/JWE Compatible

header
  Standard fields:
    "typ"           JWT, JWE or EWT
    "alg"           Signing Algorithm Name, ETW="OpenSSL Hash Name"
    "kid"           Key ID
    "cty"           Content Type
    "jku"           JWK set Url
    "jwk"           JWK Web Key
    "crit":[]
  JWE fields:
    "enc"           Cipher used to encrypt (A256GCMKW, implies IV tag)
    "zip"           Compressor used to compress
    "iv"            IV Initialization Vector
    "tag"           ?

payload
    "exp"           Expires UNIX timestamp (milliseconds)
    "nfb"           Not before UNIX timestamp
    "iat"           Issued At
    "sub"           Subject
    "iss"           Issued By
    "aud"           Audience
    "jtd"           JWT ID (server assigned) unique id

signature
  hmacsha256()


*/
namespace Spin\Helpers;

Use \Spin\Helpers\EWTInterface;

class EWT implements EWTInterface
{
  /**
   * Encode $data with params and return a Base64 string representing the EWT
   *
   * @param      string       $data    The plain-text payload to encryot
   * @param      string       $secret  The secret password to use
   * @param      string       $hash    The HMAC algorithm. 'sha256' by default
   * @param      string       $alg     The OpenSSL Cipher algorithm. AES-256-ctr
   *                                   by default
   * @param      string|null  $iv      The Initialization Vector to use.
   *                                   Autogenerated by default
   *
   * @return     string       The resulting EWT
   */
  public static function encode($data, string $secret, string $hash='sha256', string $alg='aes-256-ctr', string $iv=null )
  {
    # Make a HASH of the secret
    $secret = \openssl_digest($secret,'sha256');

    # Generate a random IV if not provided, matches for AES based algorithms
    if (empty($iv)) {
      $iv = \substr(\openssl_digest(\random_bytes(32),'sha256'),0,16);
    }

    # Encode
    $ewt = array();
    $ewt['a'] = $alg; // Needs to be verified that it contains valid value
    $ewt['i'] = $iv;
    $ewt['h'] = $hash;
    $ewt['s'] = static::sign($data,$secret,$hash);
    $ewt['p'] = \openssl_encrypt($data,$alg,$secret,0,$iv);

    return static::base64url_encode( json_encode($ewt) );
  }

  /**
   * Decode an EWT, returning the payload
   *
   * @param      string       $data    The encrypted EWT base64 string
   * @param      string       $secret  Secret password used when encrypting
   *
   * @return     string|null
   */
  public static function decode(string $data, string $secret )
  {
    # Make a HASH of the secret
    $secret = \openssl_digest($secret,'sha256');

    $b64 = static::base64url_decode($data);
    $ewt = \json_decode($b64,true);

    $payload = \openssl_decrypt($ewt['p'], $ewt['a'], $secret, 0, $ewt['i']);

    # Verify signature - if fail return null
    if (\strcasecmp(static::sign($payload,$secret,$ewt['h']),$ewt['s'])!=0) {
      return null;
    }

    return $payload;
  }

  /**
   * HMAC sign $data with $secret using $algo.
   *
   * @param      mixed   $data    Data to sign
   * @param      string  $secret  Secret to use
   * @param      string  $algo    Algorithm to use 'sha256' by default
   *
   * @return     string  HMAC string
   */
  private static function sign($data, string $secret, string $algo='sha256')
  {
    return \hash_hmac($algo, $data, $secret, false);
  }

  /**
   * URL compatible Base64 Decode
   *
   * @param      string  $input  A base64 encoded string
   *
   * @return     mixed   Decoded data
   */
  public static function base64url_decode($input)
  {
    $remainder = \strlen($input) % 4;
    if ($remainder) {
        $padlen = 4 - $remainder;
        $input .= \str_repeat('=', $padlen);
    }

    return \base64_decode(\strtr($input, '-_', '+/'));
  }

  /**
   * URL Compatible Base64 Encode
   *
   * @param      string  $input  Data to encode
   *
   * @return     string  The base64 representation of Data
   */
  public static function base64url_encode($input)
  {
    return \str_replace('=','',\strtr(\base64_encode($input), '+/', '-_'));
  }
}
