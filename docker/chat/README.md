# Contrust web chat overrides

`core/` replaces only seven core-api files on the pinned API image. Six existing vendor files are checked against their original hashes in docker/brevo/Dockerfile before copying; the seventh is the new combined-feed pager. These copies are maintained here instead of changing or pushing the official upstream submodule.

The resource and direct model accessor return at most50 feed entries by default. An authorized participant can request up to100 per page through GET int/v1/chat-channels/{channelId}/feed. Each message/log/attachment query reads at most limit+1 candidates; a stable timestamp/type/UUID merge computes the next cursor. NULL timestamps sort last. Room/organization scope is bound into the cursor and checked alongside fresh active membership on each request. No message content, token or coordinate is encoded in the cursor.

Initial feed metadata is retained by an open web window across list refreshes. Older pages prepend without duplicate socket entries or automatic scrolling away from the reader. The organization directory is separately paged via the existing participant endpoint, tied to organization, room and search. The UI keeps selected participants while paging and cancels stale searches.

Legacy optional attachment/message/file references serialize safely. New generic chat attachments remain rejected by InternalChatAccess; the web control explicitly reports that private attachments are not yet available. This change does not provide a private upload/download gateway.

Synthetic HTTP coverage: docker/release-qa/fixtures/web-chat-history-contract.php. Email and broadcast transports are log-only, and the fixture refuses all databases except contrust_release_qa. Its temporary users/history are cleaned only in that synthetic database; it performs no production cleanup or restore.
