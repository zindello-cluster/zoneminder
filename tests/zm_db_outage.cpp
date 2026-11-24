/*
 * This file is part of the ZoneMinder Project. See AUTHORS file for Copyright information
 *
 * This program is free software; you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the
 * Free Software Foundation; either version 2 of the License, or (at your
 * option) any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
 * more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program. If not, see <http://www.gnu.org/licenses/>.
 */

// Ours (AI-LIVE-DETECTION.md, item 15): the wait between tries of a new
// event's insert while the database is away or read-only. Upstream tried again
// at once, thousands of times a second, through a MariaDB switchover.

#include "zm_catch2.h"
#include "zm_db.h"

TEST_CASE("zmDbOutageBackoff", "[db]") {
  SECTION("one second first, doubling") {
    REQUIRE(zmDbOutageBackoff(1) == 1);
    REQUIRE(zmDbOutageBackoff(2) == 2);
    REQUIRE(zmDbOutageBackoff(3) == 4);
    REQUIRE(zmDbOutageBackoff(4) == 8);
    REQUIRE(zmDbOutageBackoff(5) == 16);
  }

  SECTION("never more than the cap, however long it lasts") {
    for (int attempt = 1; attempt <= 10000; attempt++) {
      REQUIRE(zmDbOutageBackoff(attempt) <= kDbOutageBackoffCap);
      REQUIRE(zmDbOutageBackoff(attempt) > 0);
    }
    REQUIRE(zmDbOutageBackoff(6) == kDbOutageBackoffCap);
    REQUIRE(zmDbOutageBackoff(1000000) == kDbOutageBackoffCap);
  }

  SECTION("never shorter than the try before") {
    for (int attempt = 1; attempt < 100; attempt++) {
      REQUIRE(zmDbOutageBackoff(attempt) <= zmDbOutageBackoff(attempt + 1));
    }
  }

  SECTION("attempt numbering starts at one") {
    REQUIRE(zmDbOutageBackoff(0) == 0);
    REQUIRE(zmDbOutageBackoff(-1) == 0);
  }
}
