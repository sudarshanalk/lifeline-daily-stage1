"""Validate the immutable source and production approvals before any SSH call."""
import hashlib, json, os, re, subprocess, sys
from pathlib import Path

def check(root, env, tag=''):
    if env not in ('staging', 'production'): raise ValueError('Unknown environment')
    hashes = {}
    for slug, name in [('protein','protein-requirement-tool.html'),('diabetes','diabetes-risk-tool.html')]:
        h = hashlib.sha256((root/'site/public_html/tools'/name).read_bytes()).hexdigest()
        manifest = json.loads((root/f'manifests/{slug}.json').read_text())
        if h != manifest['versions'][0]['version_hash']: raise ValueError(f'{slug}: manifest mismatch')
        hashes[slug] = h
        ap = root/f'approvals/{slug}.json'
        if not ap.exists():
            if env == 'production': raise ValueError(f'{slug}: approval missing')
            print(f'{slug}: staging baseline, approval not yet present'); continue
        a = json.loads(ap.read_text())
        decision = a.get('clinical_signoff',{}).get('decision')
        if a.get('version_hash') != h or decision not in (['approved'] if env == 'production' else ['approved','pending']):
            raise ValueError(f'{slug}: approval does not permit deployment')
    if env == 'production':
        if not re.fullmatch(r'rc-[A-Za-z0-9._-]+',tag): raise ValueError('Exact rc-* tag required')
        rel = json.loads((root/f'releases/{tag}.json').read_text())
        hg = json.loads((root/'approvals/hg-03.json').read_text())
        if hg.get('gate') != 'HG-03' or hg.get('decision') != 'approved' or hg.get('tag') != tag or not hg.get('signer') or not hg.get('date'):
            raise ValueError('HG-03 is not signed for this tag')
        if rel.get('tag') != tag or rel.get('tool_hashes') != hashes: raise ValueError('Release record mismatch')
        head = subprocess.check_output(['git','rev-parse','HEAD'],cwd=root,text=True).strip()
        if subprocess.check_output(['git','rev-parse',tag+'^{commit}'],cwd=root,text=True).strip() != head: raise ValueError('Tag does not match checkout')
        # Record commit is the approved source ancestor (a later commit may add the release record).
        commit = rel.get('commit','')
        if not re.fullmatch('[0-9a-f]{40}',commit): raise ValueError('Full release commit required')
        subprocess.run(['git','merge-base','--is-ancestor',commit,head],cwd=root,check=True)
        subprocess.run(['git','diff','--exit-code',commit,head,'--','site','deploy','.github/workflows'],cwd=root,check=True,stdout=subprocess.DEVNULL)
    return hashes

if __name__ == '__main__':
    try: check(Path.cwd(),sys.argv[1],os.environ.get('RELEASE_TAG',''))
    except Exception as e: sys.exit('Preflight refused: '+str(e))
