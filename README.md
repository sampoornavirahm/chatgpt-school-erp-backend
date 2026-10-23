# School ERP PHP Backend

Laravel operator portal and API service. This repository is independently deployable to a PHP host; start with [the Hostinger/shared Linux deployment notes](docs/linux-backend-deployment.md) and [backend hosting guide](docs/backend-hosting.md).

The deployment must provide PHP 8.3+, PDO MySQL, Sodium, and writable Laravel cache/storage folders. Never commit `.env`, application keys, license signing keys, database dumps, or real school records. Automated MySQL integration tests run on pushes and pull requests.
