<?php

declare(strict_types=1);

namespace Modules\Listings\Idx;

/**
 * IDX1: the starting IDX coverage: every US state (the launch states switched on), the data connectors, and the main
 * MLS boards of the launch states. Board names come from public MLS lists; HQ checks each one, sets connectors,
 * costs and fees, and makes it public. Everything here is a default for HQ to review, not a settled fact.
 */
final class StarterDirectory
{
    /** Switched on at launch. */
    public const LAUNCH_STATES = ['AL', 'FL', 'LA', 'MS', 'TX'];

    public const STATES = [
        'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas', 'CA' => 'California', 'CO' => 'Colorado', 'CT' => 'Connecticut',
        'DE' => 'Delaware', 'DC' => 'District of Columbia', 'FL' => 'Florida', 'GA' => 'Georgia', 'HI' => 'Hawaii', 'ID' => 'Idaho', 'IL' => 'Illinois',
        'IN' => 'Indiana', 'IA' => 'Iowa', 'KS' => 'Kansas', 'KY' => 'Kentucky', 'LA' => 'Louisiana', 'ME' => 'Maine', 'MD' => 'Maryland',
        'MA' => 'Massachusetts', 'MI' => 'Michigan', 'MN' => 'Minnesota', 'MS' => 'Mississippi', 'MO' => 'Missouri', 'MT' => 'Montana', 'NE' => 'Nebraska',
        'NV' => 'Nevada', 'NH' => 'New Hampshire', 'NJ' => 'New Jersey', 'NM' => 'New Mexico', 'NY' => 'New York', 'NC' => 'North Carolina',
        'ND' => 'North Dakota', 'OH' => 'Ohio', 'OK' => 'Oklahoma', 'OR' => 'Oregon', 'PA' => 'Pennsylvania', 'RI' => 'Rhode Island',
        'SC' => 'South Carolina', 'SD' => 'South Dakota', 'TN' => 'Tennessee', 'TX' => 'Texas', 'UT' => 'Utah', 'VT' => 'Vermont', 'VA' => 'Virginia',
        'WA' => 'Washington', 'WV' => 'West Virginia', 'WI' => 'Wisconsin', 'WY' => 'Wyoming',
    ];

    /**
     * key => [name, kind, status]. kind: "live" (searched at the provider when a visitor searches) or "replication"
     * (listings copied into RightAlly and kept up to date). Repliers and Spark switch on when their connectors are built.
     */
    public const CONNECTORS = [
        'repliers' => ['Repliers', 'live', 'disabled'],
        'spark' => ['Spark API (Flexmls)', 'replication', 'disabled'],
        'mls_grid' => ['MLS Grid', 'replication', 'active'],
        'trestle' => ['Cotality Trestle', 'replication', 'active'],
        'bridge' => ['Bridge Interactive', 'replication', 'active'],
        'direct_reso' => ['Direct RESO Web API', 'replication', 'active'],
    ];

    /**
     * code => [name, main state, region, main connector, backup connector, code at the connector].
     * Main connectors follow the brokers' advice (Repliers for Texas and Florida, Spark for MLS United); the rest are
     * left for HQ to choose.
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: string|null, 4: string|null, 5: string|null}>
     */
    public const BOARDS = [
        // Texas
        'HAR' => ['Houston Association of REALTORS® (HAR)', 'TX', 'Greater Houston', 'repliers', 'trestle', 'HAR'],
        'NTREIS' => ['North Texas Real Estate Information Systems (NTREIS)', 'TX', 'Dallas–Fort Worth', 'repliers', 'trestle', 'NTREIS'],
        'SABOR' => ['San Antonio Board of REALTORS® (SABOR)', 'TX', 'San Antonio', 'repliers', null, 'SABOR'],
        'UNLOCK' => ['Unlock MLS (formerly ACTRIS)', 'TX', 'Austin and Central Texas', 'repliers', 'mls_grid', 'UNLOCK'],
        'HLAR' => ['Highland Lakes Association of REALTORS®', 'TX', 'Highland Lakes', null, 'mls_grid', null],
        'GEPAR' => ['Greater El Paso Association of REALTORS®', 'TX', 'El Paso', null, null, null],
        'GMAR' => ['Greater McAllen Association of REALTORS®', 'TX', 'Rio Grande Valley', null, null, null],
        'CCAR' => ['Corpus Christi Association of REALTORS®', 'TX', 'Corpus Christi and Coastal Bend', null, null, null],
        'LAR' => ['Lubbock Association of REALTORS®', 'TX', 'Lubbock', null, null, null],
        'AAR' => ['Amarillo Association of REALTORS®', 'TX', 'Amarillo', null, null, null],
        'BCSAR' => ['Bryan-College Station Association of REALTORS®', 'TX', 'Bryan–College Station', null, null, null],
        'TYLER' => ['Greater Tyler Association of REALTORS®', 'TX', 'Tyler and East Texas', null, null, null],
        'WACO' => ['Waco Association of REALTORS® (WACOMLS)', 'TX', 'Waco', null, null, null],
        'PBBOR' => ['Permian Basin Board of REALTORS®', 'TX', 'Midland–Odessa', null, null, null],
        // Florida
        'STELLAR' => ['Stellar MLS', 'FL', 'Central and West Florida', 'repliers', 'mls_grid', 'STELLAR'],
        'MIAMI' => ['MIAMI REALTORS® MLS', 'FL', 'South Florida', 'repliers', null, 'MIAMI'],
        'BEACHES' => ['BeachesMLS', 'FL', 'Southeast Florida', 'repliers', null, 'BEACHES'],
        'NEFMLS' => ['Northeast Florida MLS (NEFMLS)', 'FL', 'Jacksonville and Northeast Florida', null, null, null],
        'FGCMLS' => ['Florida Gulf Coast MLS', 'FL', 'Fort Myers and Cape Coral', null, null, null],
        'NABOR' => ['Naples Area Board of REALTORS® (NABOR)', 'FL', 'Naples', null, null, null],
        'ECAR' => ['Emerald Coast Association of REALTORS®', 'FL', 'Fort Walton Beach and Destin', null, null, null],
        'PAR' => ['Pensacola Association of REALTORS®', 'FL', 'Pensacola', null, null, null],
        'SPACE' => ['Space Coast Association of REALTORS®', 'FL', 'Brevard County', null, null, null],
        'TBR' => ['Tallahassee Board of REALTORS®', 'FL', 'Tallahassee', null, null, null],
        'CPAR' => ['Central Panhandle Association of REALTORS®', 'FL', 'Panama City', null, null, null],
        'FKMLS' => ['Florida Keys MLS', 'FL', 'Florida Keys', null, null, null],
        // Mississippi
        'MLSUNITED' => ['MLS United', 'MS', 'Jackson metro and most of Mississippi', 'spark', 'repliers', 'mlsunited'],
        'MGCMLS' => ['Mississippi Gulf Coast MLS', 'MS', 'Mississippi Gulf Coast', null, null, null],
        'GOLDENTRI' => ['Golden Triangle Association of REALTORS®', 'MS', 'Columbus and Starkville', null, null, null],
        'NEMBOR' => ['Northeast Mississippi Board of REALTORS®', 'MS', 'Tupelo', null, null, null],
        'HATTIESBURG' => ['Hattiesburg Area Association of REALTORS®', 'MS', 'Hattiesburg', null, null, null],
        // Louisiana
        'GSREIN' => ['Gulf South Real Estate Information Network (GSREIN)', 'LA', 'New Orleans', null, null, null],
        'GBRMLS' => ['Greater Baton Rouge Association of REALTORS® MLS', 'LA', 'Baton Rouge', null, null, null],
        'ACADIANA' => ['REALTOR® Association of Acadiana', 'LA', 'Lafayette', null, null, null],
        'GSMLS' => ['Greater Southern MLS', 'LA', 'Lake Charles and Southwest Louisiana', null, null, null],
        'NWLAR' => ['Northwest Louisiana Association of REALTORS®', 'LA', 'Shreveport–Bossier City', null, null, null],
        'NELAR' => ['Northeast Louisiana Association of REALTORS®', 'LA', 'Monroe', null, null, null],
        'GCLRA' => ['Greater Central Louisiana REALTORS® Association', 'LA', 'Alexandria', null, null, null],
        'BAYOU' => ['Bayou Board of REALTORS®', 'LA', 'Houma–Thibodaux', null, null, null],
        // Alabama
        'GALMLS' => ['Greater Alabama MLS (GALMLS)', 'AL', 'Birmingham', null, null, null],
        'VALLEYMLS' => ['ValleyMLS', 'AL', 'Huntsville and North Alabama', null, null, null],
        'BCAR' => ['Baldwin County Association of REALTORS®', 'AL', 'Baldwin County and Gulf Shores', null, null, null],
        'GCMLS' => ['Gulf Coast MLS', 'AL', 'Mobile', null, null, null],
        'MAAR' => ['Montgomery Area Association of REALTORS®', 'AL', 'Montgomery', null, null, null],
        'LCAR' => ['Lee County Association of REALTORS®', 'AL', 'Auburn–Opelika', null, null, null],
        'WAMLS' => ['West Alabama MLS', 'AL', 'Tuscaloosa', null, null, null],
        'WIREGRASS' => ['Wiregrass Board of REALTORS® (Dothan)', 'AL', 'Dothan', null, null, null],
    ];
}
