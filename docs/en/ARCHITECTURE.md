# Architecture

[Documentation Home](README.md) · [فارسی](../fa/ARCHITECTURE.md)

## High-level design

```text
Telegram User
     |
     v
Telegram Bot / Mini App
     |
     v
PHP Backend
     |
     +---- MySQL command queue / metrics / events
     |
     ^ HTTPS + HMAC
     |
OpenWrt Agent (Go)
     |
     +---- UCI / ubus / procd
     +---- Passwall2
     +---- local Dropbear
```

The router initiates all normal management communication. No inbound router connection from the backend is required.

## Backend

The PHP backend is responsible for:

- Telegram webhook handling
- Telegram user allowlist enforcement
- command creation
- command leasing
- result processing
- device authentication
- event/metric persistence
- signed update delivery
- provisioning
- monitoring alerts

MySQL stores command state, device state, sessions, metrics, events, alert state and one-time provisioning tokens.

## Agent

The Go Agent runs under the OpenWrt init system and:

1. periodically sends an authenticated poll request;
2. receives at most the leased command selected by the backend;
3. executes a supported local action;
4. returns the result to the backend;
5. repeats after the configured poll interval.

The Agent also runs Smart Failover logic independently of Telegram commands.

## Command lifecycle

A command moves through states similar to:

```text
queued
  |
  v
leased
  |
  +---- result received ----> done / failed
  |
  +---- lease expired ------> queued (retry budget remains)
  |
  +---- retry exhausted ----> failed
```

Each command has an idempotency key. For mutation actions, the Agent stores the completed result in a local idempotency journal. If the backend retries a command after the router already executed it but the result was lost, the Agent can replay the prior result instead of repeating the mutation.

## Device authentication

Each installation has a Device ID and Device Secret.

Requests are authenticated using HMAC-SHA256. Server responses are also signed, allowing the Agent to verify that the response was produced by a backend that knows the same device secret.

## Provisioning

The server setup creates:

- a permanent Device Secret stored on the backend;
- a one-time random provisioning token stored only as a hash in MySQL.

The installation command contains the temporary token, not the permanent Device Secret.

The OpenWrt installer exchanges the token once through `api/provision.php`, receives the permanent configuration and stores it in `/etc/pw-agent/config.json`.

## Sensitive queue parameters

Sensitive command parameters are sealed before storage in MySQL. The queue-encryption key is derived from the device secret and AES-256-GCM is used for authenticated encryption.

## Smart Failover path

For a configured Shunt:

1. inspect the active Default node;
2. perform repeated Relay health probes;
3. check TCP reachability when address/port are available;
4. compute a health score using latency, jitter, loss and TCP state;
5. apply failure threshold/cooldown rules;
6. rank healthy normal proxy nodes;
7. switch the Shunt Default when required;
8. optionally return to the Preferred Node after recovery.

## Update architecture

Official releases provide one archive per supported architecture.

The update path verifies:

```text
downloaded bytes
   |
   +--> SHA256 == manifest SHA256
   |
   +--> Ed25519 signature valid with trusted public key
   |
   v
extract -> install-update.sh -> restart Agent
```

See [Security](SECURITY.md) for the trust model.
