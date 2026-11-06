# 构建可追溯的镜像

在服务器安装 Git、Docker 后，首次构建执行：

```bash
git clone --branch master https://github.com/put-go/Xboard.git Xboard-build
cd Xboard-build
bash build-image.sh
```

后续在独立构建目录更新并重建：

```bash
git pull --ff-only origin master
bash build-image.sh
```

标签格式为 `xboard:YYYYMMDD-HHMMSS-12位commit`，时间使用 `Asia/Shanghai`。
例如 `xboard:20261106-203000-22cd8caf1e05`。脚本完成后会输出实际镜像名。

脚本使用当前 HEAD 的已提交快照作为构建上下文，并让 Dockerfile 拉取同一个完整提交号；未提交改动不会包含在镜像中。该提交需要已推送到 `put-go/Xboard`，否则构建会失败。

可通过环境变量更改镜像仓库名：

```bash
XBOARD_IMAGE=ghcr.io/put-go/xboard bash build-image.sh
```

镜像包含 OCI 元数据：完整 Git 提交号 `revision`、UTC 构建时间 `created`、仓库地址 `source` 和日期/提交组合 `version`。用实际生成的镜像名查询：

```bash
docker image inspect xboard:20261106-203000-22cd8caf1e05 \
  --format '{{json .Config.Labels}}'
```

部署时将 `compose.yaml` 的 Xboard 服务 `image` 设置为实际生成的完整标签，在部署目录执行 `docker compose up -d --pull never`。这可确保使用本机构建的指定版本，保留旧标签方便追溯。数据库结构升级后的回滚还需要核对数据库兼容性。

GitHub Actions 构建也会额外发布同格式的日期/提交标签，并固定到触发工作流的提交。
