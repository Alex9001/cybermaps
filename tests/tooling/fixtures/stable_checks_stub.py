"""Offline workflow fixture. Real evidence validators have independent tests."""
def publication_evidence(number):
    return {'fixture': number}


def upgrade(number, digest, commit, phase, max_age_hours=24):
    return {'phase': phase, 'zip_sha256': digest, 'commit': commit}
