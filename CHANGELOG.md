## v0.0.8 (Snapshot)

- Better reprocessing error reporting [#12](https://github.com/lsuits/ues/issues/12)
- Added the DAO DSL [a73b6cd](https://github.com/lsuits/ues/commit/a73b6cd14dc98c31c4aa5ee7abd5ba54ae57b2b0)
- Fixed an [lsu provider][lsu] bug for student data [62a0b83](https://github.com/lsuits/ues/commit/62a0b83d68d17cc9aad5834080cf7b4b100c0fe8)
- Fixed a bug in teacher demotion / promotion [#17](https://github.com/lsuits/ues/issues/17)
- Fixed a small bug in meta retrieval and reporting [a53b5fe](https://github.com/lsuits/ues/commit/a53b5fe5f1bc83c598c2b307cc55c11d0d0321a1)

## v0.0.7 (Snapshot)

- Added a setting for a grace period [f5a082](https://github.com/lsuits/ues/commit/f5a082fe3052ad26c54bb22e8b63544c9b046083)
- Fixed broken running notification [#13](https://github.com/lsuits/ues/issues/13)

## v0.0.6 (Snapshot)

- Supports idnumber restoring if the course no longer has one [#12](https://github.com/lsuits/ues/issues/12)
- Now emails admin if the cron failed and stopped running for a while [#11](https://github.com/lsuits/ues/issues/11)
- Fixed a bug that would fire release on all released members [abe8d9](https://github.com/lsuits/ues/commit/abe8d9d46e05f631b3ca97d9b8f6d145b02687c5)
- Fixed _Closure Serialization Exception_ in `lsu` provider [#10](https://github.com/lsuits/ues/issues/10)
- Enrollment order was causing an enrollment exception [0ed6bd](https://github.com/lsuits/ues/commit/0ed6bd2b68496ce6b29d969139ae562c5aa2982a)

## v0.0.5 (Snapshot)

- Emergency bump

## v0.0.4 (Snapshot)

- Made cron interval 12 hours instead of 24

## v0.0.3 (Snapshot)

- Removed the UES enrollment banner [#2](https://github.com/lsuits/ues/issues/2)
- Better _from_ field in email log [#3](https://github.com/lsuits/ues/issues/3)
- Does not send blank emails [#6](https://github.com/lsuits/ues/issues/6)
- Added sort field to DAO API [#7](https://github.com/lsuits/ues/issues/7)
- Fixed user creation [#8](https://github.com/lsuits/ues/issues/8)
- Email header when reprocessing errors

## v0.0.2 (Snapshot)

- Better Moodle DB to DAO support [#5](https://github.com/lsuits/ues/issues/5)

## v0.0.1 (Snapshot)

- Initial Release (see the [wiki](https://github.com/lsuits/ues/wiki) for more details)

[lsu]: https://github.com/lsuits/ues/tree/master/plugins/lsu
