import json
import unittest
from server import Executor, dispatch


class ExecutorTests(unittest.TestCase):
    def setUp(self):
        self.now = 1000
        self.calls = []
        def api(body):
            self.calls.append(body)
            if body['operation'] == 'claim':
                return {'lease': {'run_id': 7, 'lease_token': 'secret', 'source_ids': [3]}}
            return {'recorded': True}
        self.executor = Executor(api, lambda: self.now)

    def test_claim_hides_token_and_refuses_second(self):
        self.assertNotIn('secret', json.dumps(self.executor.call({'operation': 'claim'})))
        with self.assertRaises(RuntimeError):
            self.executor.call({'operation': 'claim'})
        self.assertEqual(1, len(self.calls))

    def test_renew_independent_of_tools(self):
        self.executor.call({'operation': 'claim'})
        self.now += 120
        self.executor.renew()
        self.assertEqual('renew', self.calls[-1]['operation'])

    def test_idle_expires_without_further_api_write(self):
        self.executor.call({'operation': 'claim'})
        self.now += 601
        self.executor.renew()
        self.assertIsNone(self.executor.lease)
        with self.assertRaises(RuntimeError):
            self.executor.call({'operation': 'import'})
        self.assertEqual(1, len(self.calls))

    def test_renew_failure_stops_mutations(self):
        self.executor.call({'operation': 'claim'})
        def unavailable(body):
            raise RuntimeError('unavailable')
        self.executor.api = unavailable
        self.executor.renew()
        self.assertIsNone(self.executor.lease)
        with self.assertRaises(RuntimeError):
            self.executor.call({'operation': 'checkpoint'})

    def test_no_work_does_not_mutate(self):
        self.executor.api = lambda body: {'lease': None}
        self.assertIsNone(self.executor.call({'operation': 'claim'})['lease'])
        with self.assertRaises(RuntimeError):
            self.executor.call({'operation': 'start', 'source_id': 3})

    def test_run_and_lease_cannot_be_overridden(self):
        self.executor.call({'operation': 'claim'})
        with self.assertRaises(RuntimeError):
            self.executor.call({'operation': 'task', 'lease_token': 'another'})
        self.executor.call({'operation': 'task'})
        self.assertEqual(7, self.calls[-1]['run_id'])

    def test_mcp_protocol_has_no_submissions(self):
        self.assertEqual('2024-11-05', dispatch(self.executor, {'method': 'initialize'})['protocolVersion'])
        result = dispatch(self.executor, {'method': 'tools/call', 'params': {'name': 'execute', 'arguments': {'operation': 'apply'}}})
        self.assertTrue(result['isError'])
        self.assertEqual([], self.calls)

    def test_terminal_finish_stops_renewal_and_allows_receipt_retry(self):
        self.executor.call({'operation': 'claim'})
        self.executor.api = lambda body: {'request_status': 'complete'}
        self.executor.call({'operation': 'finish', 'source_id': 3})
        self.assertTrue(self.executor.finished)
        self.assertEqual('complete', self.executor.call({'operation': 'finish', 'source_id': 3})['request_status'])
        self.executor.api = lambda body: self.fail('Terminal run must not renew')
        self.executor.renew()

    def test_access_preparation_stops_renewal_and_keeps_receipt(self):
        schema = dispatch(self.executor, {'method': 'tools/list'})['tools'][0]['inputSchema']
        self.assertIn('prepare_access', schema['properties']['operation']['enum'])
        self.executor.call({'operation': 'claim'})
        self.executor.api = lambda body: {'request_status': 'waiting_for_login'}
        args = {'operation': 'prepare_access', 'source_id': 3, 'idempotency_key': 'prepare-source-three', 'payload': {'status': 'login_required'}}
        self.executor.call(args)
        self.assertTrue(self.executor.finished)
        self.assertEqual('waiting_for_login', self.executor.call(args)['request_status'])
        self.executor.api = lambda body: self.fail('Preparation must not renew after handoff')
        self.executor.renew()


if __name__ == '__main__':
    unittest.main()
