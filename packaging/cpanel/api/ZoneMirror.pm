package Cpanel::API::ZoneMirror;

# /usr/local/cpanel/Cpanel/API/ZoneMirror.pm
#
# UAPI surface for the ZoneMirror plugin. Exposes the user-side
# operations that cannot run as the cPanel user themselves — namely
# writing /var/cpanel/zonemirror/enrolled-users, which is root-owned by
# design. Every function in this module is a thin wrapper that calls
# Cpanel::AdminBin::Call::call() into the matching root binary at
# /usr/local/cpanel/bin/admin/Cpanel/ZoneMirror.
#
# Called from the cPanel UI via `$cpanel->uapi('ZoneMirror', 'enroll')`
# (see resources/cpanel/index.live.php). The matching action names on
# the adminbin side are upper-case ENROLL / UNENROLL / LIST.

use strict;
use warnings;

use Cpanel::AdminBin::Call ();

our $VERSION = '1.0';

# All three functions are safe for anyone with a cPanel session — the
# adminbin enforces per-user binding via $self->get_caller_username(),
# so a user cannot enroll or unenroll someone else by calling this
# from a CSRF-ed page or a script in their home. `undef` means no
# additional role/feature gate at the UAPI layer.
our %API = (
    enroll   => undef,
    unenroll => undef,
    list     => undef,
);

sub enroll {
    my ( $args, $result ) = @_;
    my $resp = eval { Cpanel::AdminBin::Call::call( 'Cpanel', 'ZoneMirror', 'ENROLL' ) };
    if ($@) {
        $result->error( 'Enrollment failed: [_1]', "$@" );
        return 0;
    }
    if ( !ref $resp || !$resp->{ok} ) {
        $result->error('Enrollment failed: backend returned an unexpected response.');
        return 0;
    }
    $result->data($resp);
    return 1;
}

sub unenroll {
    my ( $args, $result ) = @_;
    my $resp = eval { Cpanel::AdminBin::Call::call( 'Cpanel', 'ZoneMirror', 'UNENROLL' ) };
    if ($@) {
        $result->error( 'Unenrollment failed: [_1]', "$@" );
        return 0;
    }
    if ( !ref $resp || !$resp->{ok} ) {
        $result->error('Unenrollment failed: backend returned an unexpected response.');
        return 0;
    }
    $result->data($resp);
    return 1;
}

sub list {
    my ( $args, $result ) = @_;
    my $resp = eval { Cpanel::AdminBin::Call::call( 'Cpanel', 'ZoneMirror', 'LIST' ) };
    if ($@) {
        $result->error( 'Listing failed: [_1]', "$@" );
        return 0;
    }
    $result->data($resp);
    return 1;
}

1;
