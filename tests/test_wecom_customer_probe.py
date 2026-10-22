import importlib.util
from pathlib import Path
import unittest

spec = importlib.util.spec_from_file_location("probe", Path(__file__).resolve().parents[1] / "scripts/wecom_customer_probe.py")
probe = importlib.util.module_from_spec(spec)
spec.loader.exec_module(probe)


class ProbeTest(unittest.TestCase):
    def run_probe(self, pages):
        responses = iter(pages)
        def api(path, query, payload=None):
            self.assertIn(path, probe.READ_PATHS)
            if path == "gettoken":
                return {"access_token": "dummy-token"}
            if path.endswith("get_follow_user_list"):
                return {"follow_user": ["sales-a", "sales-b"]}
            return next(responses)
        return probe.probe({"corpid": "dummy", "secret": "dummy-secret", "phone": "13800000000"}, api)

    def row(self, sales, mobiles, external="customer-a"):
        return {"external_contact": {"external_userid": external, "name": "private-name"},
                "follow_info": {"userid": sales, "remark_mobiles": mobiles}}

    def test_multi_owner_across_pages_and_redacted_output(self):
        result = self.run_probe([
            {"external_contact_list": [self.row("sales-a", ["+86 13800000000"])], "next_cursor": "next"},
            {"external_contact_list": [self.row("sales-b", ["13800000000"])]},
        ])
        self.assertEqual(result["matched_sales_userids"], ["sales-a", "sales-b"])
        self.assertEqual(result["matched_customer_count"], 1)
        self.assertTrue(result["complete_in_visible_scope"])
        for private in ["13800000000", "private-name", "customer-a", "dummy-secret", "dummy-token"]:
            self.assertNotIn(private, str(result))

    def test_missing_mobile_is_not_no_friend(self):
        result = self.run_probe([{"external_contact_list": [self.row("sales-a", [])]}])
        self.assertEqual(result["status"], "NO_REMARK_MOBILE_MATCH")
        self.assertEqual(result["relationships_with_mobile"], 0)
        self.assertIn("does not prove", result["limitation"])

    def test_empty(self):
        self.assertEqual(self.run_probe([{"external_contact_list": []}])["relationships_checked"], 0)

    def test_repeated_cursor_fails(self):
        with self.assertRaises(probe.ProbeError):
            self.run_probe([{"external_contact_list": [], "next_cursor": "same"}] * 2)

    def test_invalid_phone_fails_before_network(self):
        with self.assertRaises(probe.ProbeError):
            probe.probe({"phone": "invalid"}, lambda *args: self.fail("unexpected network"))

    def test_write_endpoint_rejected(self):
        with self.assertRaises(probe.ProbeError):
            probe.request("externalcontact/remark", {})


if __name__ == "__main__":
    unittest.main()
