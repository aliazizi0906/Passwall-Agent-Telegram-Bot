<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/setup_core.php';

/** Resolve only signed, locally cached release pieces; no user-controlled paths. */
function pw_install_cached_asset_path(string $root,string $version,string $arch,string $part): string {
    if(!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:-[A-Za-z0-9._-]+)?$/D',$version))
        throw new RuntimeException('Invalid signed release version');
    if($version!==pw_setup_version())throw new RuntimeException('Release version not available');
    $arch=pw_setup_normalize_arch($arch);
    if(!in_array($part,['bundle','sha256','signature'],true))throw new RuntimeException('Invalid release asset type');
    $base=rtrim($root,'/').'/releases/pw-agent-openwrt-'.$version.'-'.$arch.'.tar.gz';
    if($part==='sha256')return $base.'.sha256';
    if($part==='signature')return $base.'.sig';
    return $base;
}
