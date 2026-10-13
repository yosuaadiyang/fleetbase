#!/usr/bin/env python3
"""Receive one bounded graph archive; intended as an SSH forced command.

No shell command, path or filename is supplied by the client. Does not extract
or deploy data. Authorize only with restrict and an expiry, then revoke the key.
"""
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import signal
import sys

DIRECTORY = Path('/opt/contrust/backups/audit-20261013-frontend/routing-incoming')
MAX_SIZE = 10 * 1024**3


def parse_request(command):
    match = re.fullmatch(r'upload-routing ([0-9a-f]{64}) ([0-9]{1,11})', command)
    if not match:
        raise ValueError('Only the upload-routing command is accepted')
    checksum, size = match.groups()
    size = int(size)
    if not 0 < size <= MAX_SIZE:
        raise ValueError('Invalid archive size')
    return checksum, size


def receive(stream, directory, checksum, expected_size):
    directory.mkdir(mode=0o700, parents=True, exist_ok=True)
    with (directory / 'upload.lock').open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        if shutil.disk_usage(directory).free < expected_size + 10 * 1024**3:
            raise ValueError('Insufficient free disk headroom')
        temporary = directory / 'indonesia-osrm-20261012.tar.gz.part'
        target = directory / 'indonesia-osrm-20261012.tar.gz'
        size = 0
        digest = hashlib.sha256()
        try:
            fd = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_TRUNC | os.O_NOFOLLOW, 0o600)
            with os.fdopen(fd, 'wb') as output:
                while True:
                    block = stream.read(min(8 * 1024**2, expected_size - size + 1))
                    if not block:
                        break
                    size += len(block)
                    if size > expected_size:
                        raise ValueError('Archive exceeds declared size')
                    digest.update(block)
                    output.write(block)
                if size != expected_size or digest.hexdigest() != checksum:
                    raise ValueError('Archive size or checksum mismatch')
                output.flush()
                os.fsync(output.fileno())
            os.replace(temporary, target)
            metadata = {'sha256': checksum, 'bytes': size, 'filename': target.name}
            (directory / 'received.json').write_text(json.dumps(metadata, indent=2))
            return metadata
        finally:
            temporary.unlink(missing_ok=True)


def main():
    def timeout(_signal, _frame):
        raise TimeoutError('Upload exceeded one hour')

    signal.signal(signal.SIGALRM, timeout)
    signal.alarm(3600)
    checksum, size = parse_request(os.environ.get('SSH_ORIGINAL_COMMAND', ''))
    print(json.dumps(receive(sys.stdin.buffer, DIRECTORY, checksum, size)))


if __name__ == '__main__':
    main()
