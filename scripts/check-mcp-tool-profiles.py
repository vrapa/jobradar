"""Verify actual PHP MCP catalogs and rejected cross-role calls without API access."""
import argparse
import json
import os
from pathlib import Path
import runpy


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--container', help='Existing container; otherwise use local PHP.')
    args = parser.parse_args()
    root = Path(__file__).resolve().parents[1]
    probe_class = runpy.run_path(str(root / 'scripts' / 'check-mcp-lifecycle.py'))['Probe']
    profiles = json.loads((root / 'config' / 'mcp-tool-profiles.json').read_text(encoding='utf-8'))
    for profile, forbidden in [('jobradar', 'get_search_status'),
                               ('jobradar-actions', 'list_sources'),
                               ('jobradar-applications', 'link_todoist_task')]:
        command = ['php', 'bin/jobradar-mcp']
        if args.container:
            command = ['docker', 'exec', '-i', '--user', 'www-data',
                       '-e', 'JOBRADAR_MCP_TOKEN=synthetic-profile-probe',
                       '-e', 'JOBRADAR_MCP_PROFILE=' + profile, args.container, *command]
        environment = {**os.environ, 'JOBRADAR_MCP_TOKEN': 'synthetic-profile-probe',
                       'JOBRADAR_MCP_PROFILE': profile}
        probe = probe_class(profile, command, environment, str(root))
        try:
            probe.request(1, 'initialize', {'protocolVersion': '2024-11-05', 'capabilities': {},
                          'clientInfo': {'name': 'synthetic-profile-probe', 'version': '1'}})
            probe.send({'jsonrpc': '2.0', 'method': 'notifications/initialized'})
            tools = probe.request(2, 'tools/list')['tools']
            assert {tool['name'] for tool in tools} == set(profiles[profile]), profile + ': unexpected catalog'
            probe.send({'jsonrpc': '2.0', 'id': 3, 'method': 'tools/call',
                        'params': {'name': forbidden, 'arguments': {}}})
            reply = json.loads(probe.lines.get(timeout=15))
            assert reply.get('id') == 3 and 'error' in reply, profile + ': cross-role call reached a tool'
            probe.request(4, 'ping')
        finally:
            assert probe.close() == 0, profile + ': unclean EOF'
        print(profile + ': catalog and cross-role rejection passed; no API call.', flush=True)


if __name__ == '__main__':
    main()
