"""Despliegue con Git por dominio (como la extensión Git de Plesk).

Clona/actualiza un repositorio en una carpeta del dominio, ejecuta acciones posteriores
como el usuario dueño y, si la app del dominio existe, la reinicia. Admite despliegue
automático con webhook (GitHub, GitLab, Gitea, Bitbucket) protegido con un secreto.
"""

import hmac
import os
import re
import secrets
import subprocess

from . import apps
from .common import (RUNUSER, USERS_DIR, HPError, check_web_domain, domain_dir, ensure_user_dir, home_of, load_json, locked,
                     log_action, now, run, safe_subpath, save_json)
from .jobs import step

RE_REPO = re.compile(r"^(https?://[^\s'\"]+|git@[A-Za-z0-9.-]+:[^\s'\"]+|ssh://[^\s'\"]+)$")
RE_BRANCH = re.compile(r"^[A-Za-z0-9_][A-Za-z0-9._/-]{0,99}$")  # sin "-" inicial: git lo tomaría como opción


def _path(user):
    return f"{USERS_DIR}/{user}/hp-git.json"


def load(user):
    return load_json(_path(user), {})


def _key_path(user):
    return f"{home_of(user)}/.ssh/hp_deploy_ed25519"


def deploy_key(user):
    key = _key_path(user)
    if not os.path.exists(key):
        run(["mkdir", "-p", os.path.dirname(key)], user=user, check=True)
        run(["chmod", "700", os.path.dirname(key)], user=user)
        run(["ssh-keygen", "-q", "-t", "ed25519", "-N", "", "-C", f"hestia-pro-{user}", "-f", key], user=user, check=True)
    with open(key + ".pub") as fh:
        return {"public_key": fh.read().strip()}


def get(user, domain):
    check_web_domain(user, domain)
    cfg = load(user).get(domain)
    if cfg:
        cfg = dict(cfg)
        target = safe_subpath(domain_dir(user, domain), cfg["path"])
        cfg["head"] = _head(user, target)
    return cfg


def _head(user, target):
    if not os.path.isdir(f"{target}/.git"):
        return None
    p = run(["git", "-C", target, "log", "-1", "--format=%h|%s|%an|%cI"], user=user, timeout=20)
    if p.returncode != 0 or "|" not in p.stdout:
        return None
    h, s, a, d = p.stdout.strip().split("|", 3)
    return {"commit": h, "subject": s, "author": a, "date": d}


def save(user, domain, cfg):
    check_web_domain(user, domain)
    repo = (cfg.get("repo") or "").strip()
    branch = (cfg.get("branch") or "main").strip()
    rel = (cfg.get("path") or "public_html").strip().strip("/")
    if not RE_REPO.match(repo):
        raise HPError("URL de repositorio no válida (https://... o git@host:org/repo.git)")
    if not RE_BRANCH.match(branch) or ".." in branch:
        raise HPError("Rama no válida")
    safe_subpath(domain_dir(user, domain), rel)
    post = (cfg.get("post_deploy") or "").replace("\r", "")
    if len(post) > 4000:
        raise HPError("Las acciones posteriores son demasiado largas")
    with locked("git-" + user):
        data = load(user)
        old = data.get(domain, {})
        data[domain] = {"repo": repo, "branch": branch, "path": rel, "post_deploy": post,
                        "auto": bool(cfg.get("auto")), "secret": old.get("secret") or secrets.token_urlsafe(24),
                        "restart_app": cfg.get("restart_app", True) is not False,
                        "last_deploy": old.get("last_deploy"), "last_status": old.get("last_status")}
        save_json(_path(user), data, 0o660)
    log_action(user, f"Hestia Pro: repositorio Git configurado en {domain}")
    return data[domain]


def delete(user, domain):
    with locked("git-" + user):
        data = load(user)
        if data.pop(domain, None) is None:
            raise HPError("Este dominio no tiene repositorio")
        save_json(_path(user), data, 0o660)
    return {"deleted": domain}


def _git_env(user):
    key = _key_path(user)
    env = {"HOME": home_of(user), "PATH": "/usr/local/bin:/usr/bin:/bin", "LANG": "C.UTF-8",
           "GIT_TERMINAL_PROMPT": "0"}
    if os.path.exists(key):
        env["GIT_SSH_COMMAND"] = f"ssh -i {key} -o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new"
    return env


def _git(user, args, cwd=None):
    p = subprocess.run([RUNUSER, "-u", user, "--", "git"] + args, cwd=cwd, env=_git_env(user), text=True,
                       stdout=None, stderr=subprocess.STDOUT, timeout=1800)
    if p.returncode != 0:
        raise HPError(f"git {args[0]} falló (código {p.returncode})")


def deploy(user, domain):
    check_web_domain(user, domain)
    cfg = load(user).get(domain)
    if not cfg:
        raise HPError("Este dominio no tiene repositorio configurado")
    target = safe_subpath(domain_dir(user, domain), cfg["path"])
    ok, err = True, None
    try:
        if os.path.isdir(f"{target}/.git"):
            step(f"Actualizando {cfg['branch']} desde {cfg['repo']}", "running")
            _git(user, ["fetch", "--prune", "origin", cfg["branch"]], cwd=target)
            _git(user, ["checkout", "-f", cfg["branch"]], cwd=target)
            _git(user, ["reset", "--hard", f"origin/{cfg['branch']}"], cwd=target)
        else:
            step(f"Clonando {cfg['repo']} ({cfg['branch']})", "running")
            if os.path.isdir(target) and os.listdir(target):
                # carpeta con contenido (p.ej. el index.html por defecto): se inicializa en sitio
                _git(user, ["init", "-q"], cwd=target)
                _git(user, ["remote", "add", "origin", cfg["repo"]], cwd=target)
                _git(user, ["fetch", "origin", cfg["branch"]], cwd=target)
                _git(user, ["checkout", "-f", "-B", cfg["branch"], f"origin/{cfg['branch']}"], cwd=target)
            else:
                ensure_user_dir(user, target)
                _git(user, ["clone", "--branch", cfg["branch"], cfg["repo"], target])
        step("Código actualizado: " + ((_head(user, target) or {}).get("subject") or ""))
        if cfg.get("post_deploy", "").strip():
            step("Ejecutando acciones posteriores", "running")
            app = apps.load_apps(user).get(domain, {})
            path = "/usr/local/bin:/usr/bin:/bin"
            if app.get("type") == "node":
                path = f"/opt/hestia-pro/runtimes/node/{app['version']}/bin:" + path
            env = dict(_git_env(user), PATH=path)
            p = subprocess.run([RUNUSER, "-u", user, "--", "/bin/bash", "-e", "-c", cfg["post_deploy"]],
                               cwd=target, env=env, text=True, stdout=None, stderr=subprocess.STDOUT, timeout=3600)
            if p.returncode != 0:
                raise HPError(f"Las acciones posteriores fallaron (código {p.returncode})")
            step("Acciones posteriores completadas")
        if cfg.get("restart_app", True) and domain in apps.load_apps(user):
            step("Reiniciando la aplicación", "running")
            apps.action(user, domain, "restart")
            step("Aplicación reiniciada")
    except Exception as e:
        ok, err = False, str(e)
        raise
    finally:
        with locked("git-" + user):
            data = load(user)
            if domain in data:
                data[domain]["last_deploy"] = now()
                data[domain]["last_status"] = "ok" if ok else ("error: " + (err or ""))[:300]
                save_json(_path(user), data, 0o660)
        log_action(user, f"Hestia Pro: despliegue Git de {domain} " + ("correcto" if ok else "fallido"),
                   "Info" if ok else "Error")
    return {"ok": True}


def webhook_check(user, domain, secret):
    cfg = load(user).get(domain) if os.path.isfile(f"{USERS_DIR}/{user}/user.conf") else None
    if not cfg or not cfg.get("auto") or not secret or not hmac.compare_digest(cfg["secret"], secret):
        raise HPError("Webhook no autorizado")
    return True
