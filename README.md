# Core Updater

This module brings the tools for keeping your shop installation up to date.

## Description

This module is designed to be a breeze for non-technical users as well as a versatile tool for experts.

- It uses the strategies also used by the popular Git tool, in a fashion easily handleable by merchants.
- One can choose between stable releases and bleeding edge versions.
- Updates are a matter of only a few clicks.
- Before an update happens, the module lists files which are going to be changed.
- Manually edited files get detected and marked in these file lists, so the coding-savy merchant can deal with them before updating.
- Manually edited files also get backed up before they get overwritten.
- Shop downtime for an update has shown to be usually less than a second.
- Downgrades are just as easy as updates. In case the most recent release leaves wishes open.
- One can even do a null-update, for comparing against and restoring to a clean installation.
- All custom shop files, like product images, customer uploads and such stuff get preserved, of course.
- Best of all this: bleeding edge versions get updated in an automated fashion, so even a non-technical merchant can update to a bugfix branch, a few minutes after it was pushed to the development repository. No more waiting for the next release or editing code files, just to get a crucial bug fixed immediately.

## License

This software is published under the [Academic Free License 3.0](https://opensource.org/licenses/afl-3.0.php)

## Contributing

Merchant's Edition modules are Open Source extensions to the Merchant's Edition e-commerce solution. Everyone is welcome and even encouraged to contribute with their own improvements.

For details, see [CONTRIBUTING.md](https://github.com/merchantsedition/merchantsedition/blob/1.9.1/CONTRIBUTING.md) in the core repository.

## Packaging

To build a package for the Merchant's Edition distribution machinery or suitable for importing it into a shop, run `tools/buildmodule.sh` of the Merchant's Edition core repository from inside the module root directory.

For module development, one clones this repository into `modules/` of the shop, alongside the other modules. It should work fine without packaging.

## Roadmap

#### Short Term

* None currently.

#### Long Term

* Implement applying single commits.
* Instead of doing updates by exchanging files, try to do them by applying patches. This should help preserving manual code changes. It also needs instrumentation to deal with conflicts.
